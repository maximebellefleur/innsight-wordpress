<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin - composition root. Wires the small set of services and registers
 * each one's WordPress hooks. Holds no business logic itself; that lives in
 * the individual classes.
 *
 * Single instance accessed via Plugin::instance(). Services exposed via
 * accessor methods so tests / extensions can swap them through the
 * `innsight/services` filter early in `plugins_loaded`.
 */
final class Plugin {

    /** @var self|null */
    private static $instance = null;

    /** @var Translator */
    private $translator;
    /** @var Geocoder */
    private $geocoder;
    /** @var DataSource */
    private $data_source;
    /** @var JsonBuilder */
    private $json_builder;
    /** @var SkinPartials */
    private $skin_partials;
    /** @var Assets */
    private $assets;
    /** @var Shortcode */
    private $shortcode;
    /** @var RestController */
    private $rest_controller;
    /** @var KmlExport */
    private $kml_export;
    /** @var Admin */
    private $admin;
    /** @var PoiPostType */
    private $poi_post_type;
    /** @var PoiImporter */
    private $poi_importer;
    /** @var PoiExporter */
    private $poi_exporter;
    /** @var ImportPage */
    private $import_page;
    /** @var LegacyCompat */
    private $legacy_compat;
    /** @var DefaultsPage */
    private $defaults_page;
    /** @var CacheManager */
    private $cache_manager;
    /** @var Stats */
    private $stats;
    /** @var AnalyticsPage */
    private $analytics_page;
    /** @var Places */
    private $places;
    /** @var LegacyAcf */
    private $legacy_acf;
    /** @var Pwa */
    private $pwa;
    /** @var LegacyTemplate */
    private $legacy_template;
    /** @var LegacyMisc */
    private $legacy_misc;
    /** @var bool */
    private $booted = false;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Service construction lives in boot() so plugin instantiation itself stays cheap.
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain( 'innsight', false, dirname( plugin_basename( INNSIGHT_FILE ) ) . '/languages' );

        $this->translator      = new Translator();
        $this->geocoder        = new Geocoder();
        $this->data_source     = new DataSource( $this->translator, $this->geocoder );
        $this->json_builder    = new JsonBuilder();
        $this->skin_partials   = new SkinPartials();
        $this->assets          = new Assets();
        $this->shortcode       = new Shortcode( $this->data_source, $this->json_builder, $this->skin_partials, $this->assets );
        $this->stats           = new Stats();
        $this->places          = new Places();
        $this->rest_controller = new RestController( $this->data_source, $this->json_builder, $this->stats, $this->places );
        $this->analytics_page  = new AnalyticsPage( $this->stats );
        $this->legacy_acf      = new LegacyAcf();
        $this->pwa             = new Pwa();
        $this->legacy_template = new LegacyTemplate();
        $this->legacy_misc     = new LegacyMisc();
        $this->kml_export      = new KmlExport( $this->data_source );
        $this->admin           = new Admin();
        $this->poi_post_type   = new PoiPostType();
        $this->poi_importer    = new PoiImporter();
        $this->poi_exporter    = new PoiExporter();
        $this->import_page     = new ImportPage( $this->poi_importer, $this->poi_exporter );
        $this->legacy_compat   = new LegacyCompat();
        $this->defaults_page   = new DefaultsPage();
        $this->cache_manager   = new CacheManager();

        /**
         * Allow extensions to swap any service before hook registration.
         * Example: replace the Geocoder with a Google geocoder.
         *
         * @param array $services
         * @param Plugin $plugin
         */
        $services = apply_filters( 'innsight/services', array(
            'translator'      => $this->translator,
            'geocoder'        => $this->geocoder,
            'data_source'     => $this->data_source,
            'json_builder'    => $this->json_builder,
            'skin_partials'   => $this->skin_partials,
            'assets'          => $this->assets,
            'shortcode'       => $this->shortcode,
            'rest_controller' => $this->rest_controller,
            'kml_export'      => $this->kml_export,
            'admin'           => $this->admin,
            'poi_post_type'   => $this->poi_post_type,
            'poi_importer'    => $this->poi_importer,
            'poi_exporter'    => $this->poi_exporter,
            'import_page'     => $this->import_page,
            'legacy_compat'   => $this->legacy_compat,
            'defaults_page'   => $this->defaults_page,
            'cache_manager'   => $this->cache_manager,
            'stats'           => $this->stats,
            'analytics_page'  => $this->analytics_page,
            'places'          => $this->places,
            'legacy_acf'      => $this->legacy_acf,
            'pwa'             => $this->pwa,
            'legacy_template' => $this->legacy_template,
            'legacy_misc'     => $this->legacy_misc,
        ), $this );
        foreach ( $services as $key => $svc ) {
            $this->{$key} = $svc;
        }

        // Front-of-house: legacy compat must register BEFORE the post type so
        // the `point_of_interest` taxonomy is available the moment WP fires
        // the `init` action chain. Both run on `init` but the LegacyCompat
        // adds itself at priority 11 (after legacy plugin's 10) so it never
        // fights an already-active legacy install.
        $this->legacy_compat->register();
        $this->poi_post_type->register();
        $this->shortcode->register();
        $this->rest_controller->register();
        $this->kml_export->register();
        $this->assets->register();
        $this->cache_manager->register();
        $this->places->register();
        $this->legacy_acf->register();
        $this->pwa->register();
        $this->legacy_template->register();
        $this->legacy_misc->register();
        if ( is_admin() ) {
            $this->admin->register();
            $this->defaults_page->register();
            $this->poi_exporter->register();
            $this->import_page->register();
            $this->analytics_page->register();
        }
    }

    public function translator(): Translator { return $this->translator; }
    public function geocoder(): Geocoder { return $this->geocoder; }
    public function data_source(): DataSource { return $this->data_source; }
    public function json_builder(): JsonBuilder { return $this->json_builder; }
    public function skin_partials(): SkinPartials { return $this->skin_partials; }
    public function assets(): Assets { return $this->assets; }
    public function places(): Places { return $this->places; }
    public function stats(): Stats { return $this->stats; }
    public function legacy_compat(): LegacyCompat { return $this->legacy_compat; }
    public function defaults_page(): DefaultsPage { return $this->defaults_page; }
    public function poi_post_type(): PoiPostType { return $this->poi_post_type; }
    public function poi_importer(): PoiImporter { return $this->poi_importer; }
    public function poi_exporter(): PoiExporter { return $this->poi_exporter; }
    public function import_page(): ImportPage { return $this->import_page; }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException( 'Cannot unserialize Innsight\\Plugin' ); }
}
