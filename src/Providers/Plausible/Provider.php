<?php

namespace Innocode\Statistics\Providers\Plausible;

use Innocode\Statistics\Abstracts\AbstractProvider;
use Innocode\Statistics\Plugin;
use Innocode\Statistics\Providers\Plausible\Entities\Event;
use Innocode\Statistics\Providers\Plausible\Entities\Site;

class Provider extends AbstractProvider {

	/**
	 * Initializes provider.
	 */
	public function __construct() {
		$this->api = new API();
	}

	/**
	 * @param Plugin $plugin
	 *
	 * @return void
	 */
	public function activate( Plugin $plugin ): void {
		$site_provisioning = $this->get_api()->get_site_provisioning();

		if ( $site_provisioning->is_enabled() ) {
			$site = new Site();
			$site->set_domain( $this->site_id() );
			$site->set_timezone( wp_timezone_string() );

			$site = $site_provisioning->create( $site );

			if ( is_wp_error( $site ) ) {
				error_log( $site->get_error_message() );
			}
		}
	}

	/**
	 * @param string $name
	 * @param string $url
	 * @param array  $props
	 *
	 * @return void
	 */
	public function push_event( string $name, string $url, array $props ): void {
		$event = new Event();
		$event->set_name( $name );
		$event->set_domain( $this->site_id() );
		$event->set_url( $url );

		foreach ( $props as $name => $prop ) {
			switch ( $name ) {
				case 'referrer':
					$event->set_referrer( $prop );
					break;
				case 'screen_width':
					$event->set_screen_width( $prop );
					break;
				default:
					$event->set_prop( $name, $prop );
					break;
			}
		}

		$this->get_api()->get_events()->push( $event );
	}

	/**
	 * @param array $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_urls( array $query = [] ): array {
		$data = $this->get_api()->get_stats()->breakdown(
			wp_parse_args(
				$query,
				[
					'property' => 'event:page',
					'period'   => '7d',
					'limit'    => get_option( 'posts_per_page' ),
				]
			)
		);

		if ( is_wp_error( $data ) ) {
			error_log( $data->get_error_message() );

			return [];
		}

		return $data;
	}

	/**
	 * @param string $template
	 * @param string $type
	 * @param array  $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_data( string $template, string $type, array $query = [] ): array {
		$event = $template;

		if ( $type ) {
			$event .= ":$type";
		}

		$data = $this->get_api()->get_stats()->breakdown(
			wp_parse_args(
				$query,
				[
					'property' => 'event:props:id',
					'period'   => '7d',
					'limit'    => get_option( 'posts_per_page' ),
					'filters'  => "event:name==$event",
				]
			)
		);

		if ( is_wp_error( $data ) ) {
			error_log( $data->get_error_message() );

			return [];
		}

		return $data;
	}

	/**
	 * @param array $query
	 * @return Entities\Breakdown[]
	 */
	public function not_found_pages( array $query = [] ): array {
		return $this->popular_data(
			Plugin::TEMPLATE_404,
			'',
			wp_parse_args(
				$query,
				[
					'property' => 'event:page',
				]
			)
		);
	}

	/**
	 * @param string   $template
	 * @param string   $type
	 * @param callable $get_objects
	 * @param callable $id_mapper
	 * @param array    $query
	 *
	 * @return Entities\Breakdown[]
	 */
	public function popular_objects( string $template, string $type, callable $get_objects, callable $id_mapper, array $query = [] ): array {
		$data        = $this->popular_data( $template, $type, $query );
		$ids         = [];
		$data_by_ids = [];

		foreach ( $data as $breakdown ) {
			$props = $breakdown->get_props();

			if ( isset( $props['id'] ) ) {
				$id = (int) $props['id'];

				if ( $id ) {
					$ids[]              = $id;
					$data_by_ids[ $id ] = $breakdown;
				}
			}
		}

		$objects = $get_objects( $ids );

		return array_map(
			function ( $object ) use ( $id_mapper, $data_by_ids ) {
				$id = $id_mapper( $object );
				/**
				 * @var Entities\Breakdown $breakdown
				 */
				$breakdown = $data_by_ids[ $id ];

				$breakdown->set_object( $object );

				return $breakdown;
			},
			$objects
		);
	}

	/**
	 * @param string $type
	 * @param array  $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_comments( string $type = 'comment', array $query = [] ): array {
		return $this->popular_objects(
			'comment',
			$type,
			[ 'Innocode\Statistics\Helpers', 'get_comments_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'comment_id' ],
			$query
		);
	}

	/**
	 * @param string $post_type
	 * @param array  $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_posts( string $post_type = 'post', array $query = [] ): array {
		return $this->popular_objects(
			Plugin::TEMPLATE_SINGULAR,
			$post_type,
			[ 'Innocode\Statistics\Helpers', 'get_posts_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'post_id' ],
			$query
		);
	}

	/**
	 * @param array $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_categories( array $query = [] ): array {
		return $this->popular_objects(
			Plugin::TEMPLATE_CATEGORY,
			'',
			[ 'Innocode\Statistics\Helpers', 'get_terms_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'term_id' ],
			$query
		);
	}

	/**
	 * @param array $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_tags( array $query = [] ): array {
		return $this->popular_objects(
			Plugin::TEMPLATE_TAG,
			'',
			[ 'Innocode\Statistics\Helpers', 'get_terms_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'term_id' ],
			$query
		);
	}

	/**
	 * @param string $taxonomy
	 * @param array  $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_terms( string $taxonomy, array $query = [] ): array {
		return $this->popular_objects(
			Plugin::TEMPLATE_TAX,
			$taxonomy,
			[ 'Innocode\Statistics\Helpers', 'get_terms_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'term_id' ],
			$query
		);
	}

	/**
	 * @param array $query
	 * @return Entities\Breakdown[]
	 */
	public function popular_authors( array $query = [] ): array {
		return $this->popular_objects(
			Plugin::TEMPLATE_AUTHOR,
			'',
			[ 'Innocode\Statistics\Helpers', 'get_authors_by_ids' ],
			[ 'Innocode\Statistics\Helpers', 'user_id' ],
			$query
		);
	}
}
