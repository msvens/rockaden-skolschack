<?php
/**
 * Reading and writing signups and their guardians.
 *
 * @package RockadenSkolschack
 */

namespace Rockaden\Skolschack\Data;

use Rockaden\Skolschack\Services\Capabilities;
use Rockaden\Skolschack\Services\Terms;
use Rockaden\Skolschack\Services\DataQuality;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that touches the signup tables.
 *
 * Raw SQL lives here and nowhere else in the plugin, which is what makes the one scoped linter
 * exclusion in phpcs.xml safe to grant: anything outside this directory that tried to write a query
 * would still fail the standard.
 *
 * Table names go through the %i placeholder rather than string interpolation, and reads cache, so
 * the prepared-SQL and caching rules are satisfied properly rather than suppressed.
 */
class SignupRepository {

	/**
	 * Cache group for signup reads.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'rockaden_skolschack';

	/**
	 * Find a signup by the key the duplicate rule uses.
	 *
	 * @param int    $term_year     Term year.
	 * @param string $term_season   Season code.
	 * @param string $identity_hash Child identity hash.
	 * @return object|null The row, or null when this child has no signup that term.
	 */
	public static function find_by_identity( int $term_year, string $term_season, string $identity_hash ): ?object {
		global $wpdb;

		$key    = sprintf( 'signup_%d_%s_%s', $term_year, $term_season, $identity_hash );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE term_year = %d AND term_season = %s AND identity_hash = %s',
				Schema::signups_table(),
				$term_year,
				$term_season,
				$identity_hash
			)
		);

		$row = is_object( $row ) ? $row : null;
		wp_cache_set( $key, null === $row ? 0 : $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * Create or update one signup.
	 *
	 * @param array<string, mixed>             $fields    Column values. term_year, term_season and
	 *                                                    identity_hash are required.
	 * @param array<int, array<string, mixed>> $guardians The guardians about to be written, used to
	 *                                                    work out what needs a person's attention.
	 * @return int The signup id.
	 * @throws \RuntimeException When the write fails.
	 */
	public static function save( array $fields, array $guardians = [] ): int {
		global $wpdb;

		// Worked out here rather than read back afterwards: the caller already knows both
		// halves, so nothing needs re-querying and the value cannot go stale.
		$flags                     = DataQuality::flags_for( $fields, $guardians );
		$fields['needs_attention'] = [] === $flags ? 0 : 1;
		$fields['flags']           = implode( ',', $flags );

		$now      = current_time( 'mysql' );
		$existing = self::find_by_identity(
			(int) $fields['term_year'],
			(string) $fields['term_season'],
			(string) $fields['identity_hash']
		);

		$cache_key = sprintf( 'signup_%d_%s_%s', (int) $fields['term_year'], (string) $fields['term_season'], (string) $fields['identity_hash'] );

		if ( null !== $existing ) {
			$fields['updated_at'] = $now;
			wp_cache_delete( $cache_key, self::CACHE_GROUP );
			$result = $wpdb->update(
				Schema::signups_table(),
				$fields,
				[ 'id' => (int) $existing->id ]
			);

			if ( false === $result ) {
				throw new \RuntimeException( sprintf( 'Could not update signup %d: %s', (int) $existing->id, esc_html( $wpdb->last_error ) ) );
			}

			self::flush_cache();

			return (int) $existing->id;
		}

		$fields['created_at'] = $fields['created_at'] ?? $now;
		$fields['updated_at'] = $now;
		wp_cache_delete( $cache_key, self::CACHE_GROUP );

		if ( false === $wpdb->insert( Schema::signups_table(), $fields ) ) {
			throw new \RuntimeException( sprintf( 'Could not insert signup: %s', esc_html( $wpdb->last_error ) ) );
		}

		self::flush_cache();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace a signup's guardians.
	 *
	 * Replacing rather than appending keeps a re-run idempotent: the guardians always reflect the
	 * source, and running twice does not double them.
	 *
	 * @param int                                                                                 $signup_id Signup id.
	 * @param array<int, array{name?: string, phone?: string, email?: string, is_primary?: bool}> $guardians Guardians.
	 * @return int How many were written.
	 */
	public static function replace_guardians( int $signup_id, array $guardians ): int {
		global $wpdb;

		wp_cache_delete( 'guardian_total', self::CACHE_GROUP );
		$wpdb->delete( Schema::guardians_table(), [ 'signup_id' => $signup_id ] );

		$written = 0;
		foreach ( $guardians as $guardian ) {
			$ok = $wpdb->insert(
				Schema::guardians_table(),
				[
					'signup_id'  => $signup_id,
					'name'       => (string) ( $guardian['name'] ?? '' ),
					'phone'      => (string) ( $guardian['phone'] ?? '' ),
					'email'      => (string) ( $guardian['email'] ?? '' ),
					'is_primary' => ! empty( $guardian['is_primary'] ) ? 1 : 0,
					'created_at' => current_time( 'mysql' ),
				]
			);

			if ( false !== $ok ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * How many signups need somebody to look at them.
	 *
	 * @return int Row count.
	 */
	public static function needs_attention_count(): int {
		global $wpdb;

		$cached = wp_cache_get( 'needs_attention_total', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE needs_attention = 1', Schema::signups_table() )
		);
		wp_cache_set( 'needs_attention_total', $count, self::CACHE_GROUP );

		return $count;
	}

	/**
	 * How many signups exist, per year.
	 *
	 * @return array<string, int> Year mapped to the number of signups.
	 */
	public static function count_per_year(): array {
		global $wpdb;

		$cached = wp_cache_get( 'count_per_year', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT term_year AS year, COUNT(*) AS n FROM %i GROUP BY term_year ORDER BY term_year',
				Schema::signups_table()
			)
		);

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row->year ] = (int) $row->n;
		}

		wp_cache_set( 'count_per_year', $out, self::CACHE_GROUP );

		return $out;
	}

	/**
	 * How many signups exist, per school name as recorded at signup time.
	 *
	 * @return array<string, int> School name mapped to the number of signups.
	 */
	public static function count_per_school(): array {
		global $wpdb;

		$cached = wp_cache_get( 'count_per_school', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT school_name, COUNT(*) AS n FROM %i GROUP BY school_name ORDER BY n DESC',
				Schema::signups_table()
			)
		);

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row->school_name ] = (int) $row->n;
		}

		wp_cache_set( 'count_per_school', $out, self::CACHE_GROUP );

		return $out;
	}

	/**
	 * How many signups each school has, keyed by school post id.
	 *
	 * Counted by the relation rather than by the name snapshot, so a renamed school still reports
	 * its own children.
	 *
	 * @return array<int, int> School post id mapped to the number of signups.
	 */
	public static function count_per_school_id(): array {
		global $wpdb;

		$cached = wp_cache_get( 'count_per_school_id', self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT school_id, COUNT(*) AS n FROM %i WHERE school_id > 0 GROUP BY school_id',
				Schema::signups_table()
			)
		);

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (int) $row->school_id ] = (int) $row->n;
		}

		wp_cache_set( 'count_per_school_id', $out, self::CACHE_GROUP );

		return $out;
	}

	/**
	 * Total signups.
	 *
	 * @return int Row count.
	 */
	public static function count(): int {
		return self::cached_count( 'signup_total', Schema::signups_table() );
	}

	/**
	 * Total guardians.
	 *
	 * @return int Row count.
	 */
	public static function guardian_count(): int {
		return self::cached_count( 'guardian_total', Schema::guardians_table() );
	}

	/**
	 * Count rows in one of our tables, through the object cache.
	 *
	 * @param string $key   Cache key.
	 * @param string $table Table name.
	 * @return int Row count.
	 */
	private static function cached_count( string $key, string $table ): int {
		global $wpdb;

		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		wp_cache_set( $key, $count, self::CACHE_GROUP );

		return $count;
	}


	/**
	 * Columns a caller may sort by.
	 *
	 * An allowlist rather than a sanitised string. A column name reaching ORDER BY is an
	 * identifier, not a value, and no amount of escaping makes an arbitrary one safe there. Only
	 * a name on this list is ever used, and anything else falls back to the default.
	 *
	 * @var array<string, string>
	 */
	private const SORTABLE = [
		'name'       => 'child_last_name',
		'school'     => 'school_name',
		'term'       => 'term_year',
		'class'      => 'child_class',
		'registered' => 'created_at',
	];

	/**
	 * Signups matching a set of filters, narrowed to what the current user may see.
	 *
	 * The scoping is applied here, deliberately, and there is no argument that disables it. A
	 * screen cannot forget it and a hand-edited URL cannot widen it. Hiding a control is a
	 * courtesy to the person looking at it; it is not what decides who may read a child's
	 * record, and the only place that can decide it is where the rows are fetched.
	 *
	 * @param array<string, mixed> $args school_id, term_year, term_season, needs_attention,
	 *                                   search, orderby, order, page, per_page.
	 * @return array<int, object> Matching rows.
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$filters   = self::filter_values( $args );
		$column    = self::SORTABLE[ (string) ( $args['orderby'] ?? '' ) ] ?? 'created_at';
		$per_page  = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset    = max( 0, ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page );
		$ascending = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) );

		$cache_key = 'query_' . md5( (string) wp_json_encode( [ $filters, $column, $ascending, $per_page, $offset ] ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// The statement is written out per sort direction rather than assembled from parts.
		// A direction cannot be a bound value, and building the clause as a string would put an
		// identifier into the query that the linter cannot prove is safe. Two literals are worth
		// more than an escape hatch.
		if ( $ascending ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE ( %d = 1 OR FIND_IN_SET( school_id, %s ) > 0 )
						AND ( %d = 0 OR school_id = %d )
						AND ( %d = 0 OR term_year = %d )
						AND ( %s = \'\' OR term_season = %s )
						AND ( %d = 0 OR needs_attention = 1 )
						AND ( %s = \'\' OR child_first_name LIKE %s OR child_last_name LIKE %s OR school_name LIKE %s )
						ORDER BY %i ASC, id DESC LIMIT %d OFFSET %d',
					Schema::signups_table(),
					$filters['unrestricted'],
					$filters['allowed'],
					$filters['has_school'],
					$filters['school'],
					$filters['has_year'],
					$filters['year'],
					$filters['season'],
					$filters['season'],
					$filters['attention'],
					$filters['search'],
					$filters['like'],
					$filters['like'],
					$filters['like'],
					$column,
					$per_page,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE ( %d = 1 OR FIND_IN_SET( school_id, %s ) > 0 )
						AND ( %d = 0 OR school_id = %d )
						AND ( %d = 0 OR term_year = %d )
						AND ( %s = \'\' OR term_season = %s )
						AND ( %d = 0 OR needs_attention = 1 )
						AND ( %s = \'\' OR child_first_name LIKE %s OR child_last_name LIKE %s OR school_name LIKE %s )
						ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
					Schema::signups_table(),
					$filters['unrestricted'],
					$filters['allowed'],
					$filters['has_school'],
					$filters['school'],
					$filters['has_year'],
					$filters['year'],
					$filters['season'],
					$filters['season'],
					$filters['attention'],
					$filters['search'],
					$filters['like'],
					$filters['like'],
					$filters['like'],
					$column,
					$per_page,
					$offset
				)
			);
		}

		$rows = is_array( $rows ) ? $rows : [];
		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP );

		return $rows;
	}

	/**
	 * How many signups match, ignoring paging.
	 *
	 * @param array<string, mixed> $args Same filters as query().
	 * @return int Row count.
	 */
	public static function count_matching( array $args = [] ): int {
		global $wpdb;

		$filters   = self::filter_values( $args );
		$cache_key = 'count_' . md5( (string) wp_json_encode( $filters ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE ( %d = 1 OR FIND_IN_SET( school_id, %s ) > 0 )
					AND ( %d = 0 OR school_id = %d )
					AND ( %d = 0 OR term_year = %d )
					AND ( %s = \'\' OR term_season = %s )
					AND ( %d = 0 OR needs_attention = 1 )
					AND ( %s = \'\' OR child_first_name LIKE %s OR child_last_name LIKE %s OR school_name LIKE %s )',
				Schema::signups_table(),
				$filters['unrestricted'],
				$filters['allowed'],
				$filters['has_school'],
				$filters['school'],
				$filters['has_year'],
				$filters['year'],
				$filters['season'],
				$filters['season'],
				$filters['attention'],
				$filters['search'],
				$filters['like'],
				$filters['like'],
				$filters['like']
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP );

		return $count;
	}

	/**
	 * The bound values behind the fixed filter clauses, scoping first.
	 *
	 * Each filter is switched on by its own value rather than by adding a clause, which keeps the
	 * statement a literal string. At this size the cost is nothing: the club has a few hundred
	 * rows, and the alternative is an unprovable query.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{unrestricted: int, allowed: string, has_school: int, school: int, has_year: int, year: int, season: string, attention: int, search: string, like: string} Filter values.
	 */
	private static function filter_values( array $args ): array {
		global $wpdb;

		$unrestricted = Capabilities::can_see_all_schools() ? 1 : 0;
		// An empty list matches nothing, which is the right answer for somebody who
		// coordinates no school at all.
		$allowed = 1 === $unrestricted ? '' : implode( ',', Capabilities::coordinator_school_ids() );

		$school = (int) ( $args['school_id'] ?? 0 );
		$year   = (int) ( $args['term_year'] ?? 0 );
		$season = (string) ( $args['term_season'] ?? '' );
		$search = trim( (string) ( $args['search'] ?? '' ) );
		$like   = '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%';

		return [
			'unrestricted' => $unrestricted,
			'allowed'      => $allowed,
			'has_school'   => $school > 0 ? 1 : 0,
			'school'       => $school,
			'has_year'     => $year > 0 ? 1 : 0,
			'year'         => $year,
			'season'       => $season,
			'attention'    => empty( $args['needs_attention'] ) ? 0 : 1,
			'search'       => $search,
			'like'         => $like,
		];
	}

	/**
	 * Guardians for several signups at once.
	 *
	 * Batched on purpose: a list of fifty children would otherwise issue fifty queries.
	 *
	 * @param array<int, int> $signup_ids Signup ids.
	 * @return array<int, array<int, object>> Signup id mapped to its guardians.
	 */
	public static function guardians_for( array $signup_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $signup_ids ) ) );

		if ( [] === $ids ) {
			return [];
		}

		$cache_key = 'guardians_' . md5( (string) wp_json_encode( $ids ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE signup_id IN ( ' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ' ) ORDER BY is_primary DESC, id ASC',
				array_merge( [ Schema::guardians_table() ], $ids )
			)
		);

		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (int) $row->signup_id ][] = $row;
		}

		wp_cache_set( $cache_key, $out, self::CACHE_GROUP );

		return $out;
	}

	/**
	 * Terms that actually have signups, newest first, for the filter dropdown.
	 *
	 * @return array<int, object> Rows with term_year and term_season.
	 */
	public static function terms_in_use(): array {
		global $wpdb;

		$cached = wp_cache_get( 'terms_in_use', self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT term_year, term_season FROM %i ORDER BY term_year DESC, FIELD( term_season, %s, %s ) DESC',
				Schema::signups_table(),
				Terms::SEASON_SPRING,
				Terms::SEASON_AUTUMN
			)
		);

		$rows = is_array( $rows ) ? $rows : [];
		wp_cache_set( 'terms_in_use', $rows, self::CACHE_GROUP );

		return $rows;
	}

	/**
	 * One signup by id, narrowed to what the current user may see.
	 *
	 * Returns null rather than the row when the signup exists but belongs to a school this person
	 * does not coordinate. A screen asking for an id out of scope therefore gets nothing, which is
	 * the same answer it would get for an id that does not exist at all.
	 *
	 * @param int $id Signup id.
	 * @return object|null The row, or null when it is absent or out of scope.
	 */
	public static function find( int $id ): ?object {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$cache_key = 'find_' . $id . '_' . get_current_user_id();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		$filters = self::filter_values( [] );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d
					AND ( %d = 1 OR FIND_IN_SET( school_id, %s ) > 0 )',
				Schema::signups_table(),
				$id,
				$filters['unrestricted'],
				$filters['allowed']
			)
		);

		$row = is_object( $row ) ? $row : null;
		wp_cache_set( $cache_key, null === $row ? 0 : $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * One signup by id, ignoring who is asking.
	 *
	 * Named so it is obvious, and deliberately separate from find(). The system itself sometimes
	 * has to read a row nobody is logged in for: a public submission sends its confirmation with no
	 * current user at all, so the scoped finder returns nothing and the email is never sent.
	 *
	 * Use find() from anything a person drives. Use this only where the actor is the plugin.
	 *
	 * @param int $id Signup id.
	 * @return object|null The row, or null when it does not exist.
	 */
	public static function find_unscoped( int $id ): ?object {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$cache_key = 'unscoped_' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::signups_table(), $id )
		);

		$row = is_object( $row ) ? $row : null;
		wp_cache_set( $cache_key, null === $row ? 0 : $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * The guardians of one signup, primary first.
	 *
	 * @param int $signup_id Signup id.
	 * @return array<int, object> Guardians.
	 */
	public static function guardians_of( int $signup_id ): array {
		$all = self::guardians_for( [ $signup_id ] );

		return $all[ $signup_id ] ?? [];
	}

	/**
	 * Whether another signup already holds this identity in this term.
	 *
	 * Editing a name or a personnummer changes the identity hash, which can collide with a child
	 * already registered that term and hit the unique index. Asking first turns a database error
	 * into a message naming the other child.
	 *
	 * @param int    $exclude_id    The signup being edited, which does not count as a clash.
	 * @param int    $term_year     Term year.
	 * @param string $term_season   Season code.
	 * @param string $identity_hash Proposed identity.
	 * @return object|null The colliding row, or null when the identity is free.
	 */
	public static function find_clash( int $exclude_id, int $term_year, string $term_season, string $identity_hash ): ?object {
		global $wpdb;

		$cache_key = sprintf( 'clash_%d_%d_%s_%s', $exclude_id, $term_year, $term_season, $identity_hash );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE term_year = %d AND term_season = %s
					AND identity_hash = %s AND id <> %d',
				Schema::signups_table(),
				$term_year,
				$term_season,
				$identity_hash,
				$exclude_id
			)
		);

		$row = is_object( $row ) ? $row : null;
		wp_cache_set( $cache_key, null === $row ? 0 : $row, self::CACHE_GROUP );

		return $row;
	}

	/**
	 * Update one signup by its id, and replace its guardians.
	 *
	 * Distinct from save(), which finds its row by identity. That is right for an import, where the
	 * identity is what identifies the child, and wrong for an edit, where the identity is one of
	 * the things being changed.
	 *
	 * @param int                              $id        Signup id.
	 * @param array<string, mixed>             $fields    Column values.
	 * @param array<int, array<string, mixed>> $guardians Guardians to store.
	 * @return void
	 * @throws \RuntimeException When the write fails.
	 */
	public static function update_by_id( int $id, array $fields, array $guardians ): void {
		global $wpdb;

		$flags                     = DataQuality::flags_for( $fields, $guardians );
		$fields['needs_attention'] = [] === $flags ? 0 : 1;
		$fields['flags']           = implode( ',', $flags );
		$fields['updated_at']      = current_time( 'mysql' );

		wp_cache_delete( 'find_' . $id . '_' . get_current_user_id(), self::CACHE_GROUP );
		self::flush_cache();

		if ( false === $wpdb->update( Schema::signups_table(), $fields, [ 'id' => $id ] ) ) {
			throw new \RuntimeException( sprintf( 'Could not update signup %s: %s', esc_html( (string) $id ), esc_html( $wpdb->last_error ) ) );
		}

		self::replace_guardians( $id, $guardians );
		self::flush_cache();
	}

	/**
	 * Remove a signup and its guardians.
	 *
	 * @param int $id Signup id.
	 * @return bool Whether anything was removed.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		wp_cache_delete( 'find_' . $id . '_' . get_current_user_id(), self::CACHE_GROUP );
		self::flush_cache();

		$wpdb->delete( Schema::guardians_table(), [ 'signup_id' => $id ] );
		$removed = $wpdb->delete( Schema::signups_table(), [ 'id' => $id ] );

		self::flush_cache();

		return is_int( $removed ) && $removed > 0;
	}

	/**
	 * Drop cached reads after a write.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
