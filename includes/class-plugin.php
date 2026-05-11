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
        $this->rest_controller = new RestController( $this->data_source, $this->json_builder );
        $this->kml_export      = new KmlExport( $this->data_source );
        $this->admin           = new Admin();
        $this->poi_post_type   = new PoiPostType();
        $this->poi_importer    = new PoiImporter();
        $this->poi_exporter    = new PoiExporter();
        $this->import_page     = new ImportPage( $this->poi_importer, $this->poi_exporter );

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
        ), $this );
        foreach ( $services as $key => $svc ) {
            $this->{$key} = $svc;
        }

        $this->poi_post_type->register();
        $this->shortcode->register();
        $this->rest_controller->register();
        $this->kml_export->register();
        $this->assets->register();
        if ( is_admin() ) {
            $this->admin->register();
            $this->poi_exporter->register();
            $this->import_page->register();
        }
    }

    public function translator(): Translator { return $this->translator; }
    public function geocoder(): Geocoder { return $this->geocoder; }
    public function data_source(): DataSource { return $this->data_source; }
    public function json_builder(): JsonBuilder { return $this->json_builder; }
    public function skin_partials(): SkinPartials { return $this->skin_partials; }
    public function assets(): Assets { return $this->assets; }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException( 'Cannot unserialize Innsight\\Plugin' ); }
}
