<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function corsivo_focal_point_default_settings() {
	return array(
		'focal_point_enabled'    => true,
		'post_types'             => array( 'post' ),
		'woocommerce_enabled'    => false,
		'wpml_copy_once_enabled' => false,
		'delete_on_uninstall'    => false,
		'optimization_enabled'   => false,
		'image_quality'          => 82,
		'max_dimension'          => 2560,
		'webp_enabled'           => false,
	);
}

function corsivo_media_setting_integer( $value, $default, $minimum, $maximum ) {
	if ( ! is_scalar( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
		return $default;
	}
	return (int) max( $minimum, min( $maximum, round( (float) $value ) ) );
}

function corsivo_media_normalize_settings( $settings ) {
	$defaults = corsivo_focal_point_default_settings();
	foreach ( array( 'focal_point_enabled', 'optimization_enabled', 'webp_enabled' ) as $key ) {
		$settings[ $key ] = in_array( $settings[ $key ] ?? $defaults[ $key ], array( true, 1, '1' ), true );
	}
	$settings['image_quality'] = corsivo_media_setting_integer( $settings['image_quality'] ?? 82, 82, 40, 100 );
	$settings['max_dimension'] = corsivo_media_setting_integer( $settings['max_dimension'] ?? 2560, 2560, 256, 8192 );
	return $settings;
}

function corsivo_focal_point_sanitize_post_types( $post_types ) {
	$post_types = array_filter( (array) $post_types, 'is_scalar' );
	$post_types = array_map(
		function ( $post_type ) {
			return sanitize_key( (string) $post_type );
		},
		$post_types
	);

	return array_values( array_unique( array_filter( $post_types ) ) );
}

function corsivo_focal_point_initialize_site() {
	if ( false === get_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, false ) ) {
		add_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, corsivo_focal_point_default_settings(), '', true );
	}
}
add_action( 'plugins_loaded', 'corsivo_focal_point_initialize_site', 5 );

function corsivo_focal_point_get_settings() {
	$defaults = corsivo_focal_point_default_settings();
	$saved    = get_option( CORSIVO_FOCAL_POINT_SETTINGS_OPTION, false );

	if ( false === $saved ) {
		return $defaults;
	}

	if ( ! is_array( $saved ) ) {
		return $defaults;
	}

	$settings                           = array_replace( $defaults, $saved );
	$settings['post_types']             = array_values( array_diff( corsivo_focal_point_sanitize_post_types( $settings['post_types'] ), array( 'product' ) ) );
	$settings['woocommerce_enabled']    = ! empty( $settings['woocommerce_enabled'] );
	$settings['wpml_copy_once_enabled'] = ! empty( $settings['wpml_copy_once_enabled'] );
	$settings['delete_on_uninstall']    = ! empty( $settings['delete_on_uninstall'] );

	return corsivo_media_normalize_settings( $settings );
}

function corsivo_focal_point_available_post_types() {
	$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );

	$post_types = array_filter(
		$post_types,
		function ( $post_type ) {
			return 'product' !== $post_type->name && post_type_supports( $post_type->name, 'thumbnail' );
		}
	);

	uasort(
		$post_types,
		function ( $first, $second ) {
			return strcasecmp( $first->labels->name, $second->labels->name );
		}
	);

	return $post_types;
}

function corsivo_focal_point_sanitize_settings( $input ) {
	$input      = is_array( $input ) ? $input : array();
	$available  = array_keys( corsivo_focal_point_available_post_types() );
	$post_types = corsivo_focal_point_sanitize_post_types( $input['post_types'] ?? array() );

	$settings = array(
		'post_types'             => array_values( array_intersect( $post_types, $available ) ),
		'woocommerce_enabled'    => ! empty( $input['woocommerce_enabled'] ),
		'wpml_copy_once_enabled' => ! empty( $input['wpml_copy_once_enabled'] ),
		'delete_on_uninstall'    => ! empty( $input['delete_on_uninstall'] ),
	);
	// Older integrations may still submit only the historical focal point fields.
	$current = corsivo_focal_point_get_settings();
	foreach ( array( 'focal_point_enabled', 'optimization_enabled', 'image_quality', 'max_dimension', 'webp_enabled' ) as $key ) {
		$settings[ $key ] = array_key_exists( $key, $input ) ? $input[ $key ] : $current[ $key ];
	}
	return corsivo_media_normalize_settings( $settings );
}

function corsivo_focal_point_register_settings() {
	register_setting(
		'corsivo_focal_point_settings',
		CORSIVO_FOCAL_POINT_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'corsivo_focal_point_sanitize_settings',
			'default'           => corsivo_focal_point_default_settings(),
		)
	);
}
add_action( 'admin_init', 'corsivo_focal_point_register_settings' );

function corsivo_focal_point_add_settings_page() {
	add_media_page(
		__( 'Corsivo Media', 'corsivo-focal-point' ),
		__( 'Impostazioni media', 'corsivo-focal-point' ),
		'manage_options',
		'corsivo-media',
		'corsivo_focal_point_render_settings_page'
	);
}
add_action( 'admin_menu', 'corsivo_focal_point_add_settings_page' );

function corsivo_focal_point_redirect_legacy_settings_page() {
	global $pagenow;
	if ( ! in_array( $pagenow, array( 'options-general.php', 'upload.php', 'admin.php' ), true )
		|| 'corsivo-focal-point' !== ( $_GET['page'] ?? '' )
		|| ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$url = admin_url( 'upload.php?page=corsivo-media' );
	if ( isset( $_GET['settings-updated'] ) ) {
		$url = add_query_arg( 'settings-updated', 'true', $url );
	}
	wp_safe_redirect( $url );
	exit;
}
add_action( 'admin_menu', 'corsivo_focal_point_redirect_legacy_settings_page', 20 );

function corsivo_focal_point_is_woocommerce_active() {
	return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
}

function corsivo_focal_point_is_wpml_active() {
	return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) || has_filter( 'wpml_element_trid' );
}

function corsivo_focal_point_is_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
}

function corsivo_focal_point_enqueue_settings_workspace( $hook ) {
	if ( 'media_page_corsivo-media' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'corsivo-focal-point-workspace', CORSIVO_FOCAL_POINT_URL . 'assets/admin-workspace.css', array(), CORSIVO_FOCAL_POINT_VERSION );
	wp_enqueue_style( 'corsivo-media-settings', CORSIVO_FOCAL_POINT_URL . 'assets/media-settings.css', array( 'corsivo-focal-point-workspace' ), CORSIVO_FOCAL_POINT_VERSION );
	wp_enqueue_script( 'corsivo-focal-point-workspace', CORSIVO_FOCAL_POINT_URL . 'assets/admin-workspace.js', array(), CORSIVO_FOCAL_POINT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'corsivo_focal_point_enqueue_settings_workspace' );

function corsivo_media_render_upload_settings( $settings ) {
	$option = CORSIVO_FOCAL_POINT_SETTINGS_OPTION;
	$webp_supported = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	?>
	<section id="media-section-optimization" data-corsivo-section="optimization" aria-labelledby="media-optimization-title">
		<h2 id="media-optimization-title"><?php esc_html_e( 'Ottimizzazione', 'corsivo-focal-point' ); ?></h2>
		<p><?php esc_html_e( 'Riduci il peso e le dimensioni delle nuove immagini JPEG e PNG. Il file originale viene conservato.', 'corsivo-focal-point' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Nuovi caricamenti', 'corsivo-focal-point' ); ?></th>
				<td><label>
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>[optimization_enabled]" value="0">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[optimization_enabled]" value="1" <?php checked( $settings['optimization_enabled'] ); ?>>
					<?php esc_html_e( 'Ottimizza le nuove immagini', 'corsivo-focal-point' ); ?>
				</label><p class="description"><?php esc_html_e( 'Applica la qualità e il limite di dimensione indicati sotto. I media già caricati restano invariati.', 'corsivo-focal-point' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="corsivo-media-quality"><?php esc_html_e( 'Qualità JPEG e WebP', 'corsivo-focal-point' ); ?></label></th>
				<td><input type="number" class="small-text" id="corsivo-media-quality" name="<?php echo esc_attr( $option ); ?>[image_quality]" value="<?php echo esc_attr( $settings['image_quality'] ); ?>" min="40" max="100" step="1" required aria-describedby="corsivo-media-quality-help">
					<p class="description" id="corsivo-media-quality-help"><?php esc_html_e( 'Da 40 a 100. Il valore iniziale è 82: valori più alti privilegiano la qualità e possono produrre file più pesanti. I PNG conservati in PNG restano senza perdita.', 'corsivo-focal-point' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="corsivo-media-dimension"><?php esc_html_e( 'Lato più lungo', 'corsivo-focal-point' ); ?></label></th>
				<td><input type="number" class="small-text" id="corsivo-media-dimension" name="<?php echo esc_attr( $option ); ?>[max_dimension]" value="<?php echo esc_attr( $settings['max_dimension'] ); ?>" min="256" max="8192" step="1" required aria-describedby="corsivo-media-dimension-help"> px
					<p class="description" id="corsivo-media-dimension-help"><?php esc_html_e( 'Da 256 a 8192 pixel. Le immagini più grandi vengono ridotte mantenendo le proporzioni; quelle più piccole non vengono ingrandite.', 'corsivo-focal-point' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="corsivo-media-note"><?php esc_html_e( 'L’originale resta disponibile: conservare sia l’originale sia la versione ottimizzata può aumentare lo spazio occupato sul server.', 'corsivo-focal-point' ); ?></p>
	</section>
	<section id="media-section-formats" data-corsivo-section="formats" aria-labelledby="media-formats-title">
		<h2 id="media-formats-title"><?php esc_html_e( 'Formati', 'corsivo-focal-point' ); ?></h2>
		<p><?php esc_html_e( 'Scegli il formato delle nuove immagini. La conversione può essere attivata anche senza cambiare qualità e dimensioni.', 'corsivo-focal-point' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'WebP', 'corsivo-focal-point' ); ?></th>
				<td><label>
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>[webp_enabled]" value="0">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[webp_enabled]" value="1" <?php checked( $settings['webp_enabled'] ); ?>>
					<?php esc_html_e( 'Converti i nuovi JPEG e PNG in WebP', 'corsivo-focal-point' ); ?>
				</label><p class="description"><?php esc_html_e( 'L’originale rimane conservato. Le immagini animate e gli altri formati non vengono convertiti.', 'corsivo-focal-point' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Disponibilità', 'corsivo-focal-point' ); ?></th>
				<td><p><?php echo esc_html( $webp_supported
					? __( 'Questo server supporta la generazione di immagini WebP.', 'corsivo-focal-point' )
					: __( 'WebP non è disponibile su questo server. Le immagini manterranno il formato originale finché il supporto non sarà disponibile.', 'corsivo-focal-point' ) ); ?></p></td>
			</tr>
		</table>
		<p class="corsivo-media-note"><?php esc_html_e( 'Se la conversione non riesce, viene mantenuta l’immagine nel formato originale. La conversione non garantisce un file più piccolo in ogni caso.', 'corsivo-focal-point' ); ?></p>
	</section>
	<?php
}

function corsivo_focal_point_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings   = corsivo_focal_point_get_settings();
	$post_types = corsivo_focal_point_available_post_types();
	?>
	<div class="wrap corsivo-admin-workspace" data-corsivo-workspace>
		<h1><?php echo esc_html__( 'Corsivo Media', 'corsivo-focal-point' ); ?></h1>
		<p><?php echo esc_html__( 'Gestisci il punto focale e prepara le nuove immagini per il sito. Le impostazioni di ottimizzazione e formato si applicano solo ai nuovi caricamenti.', 'corsivo-focal-point' ); ?></p>
		<?php settings_errors(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'corsivo_focal_point_settings' ); ?>
			<div class="corsivo-admin-workspace__layout">
				<nav class="corsivo-admin-workspace__sidebar" aria-label="<?php esc_attr_e( 'Impostazioni media', 'corsivo-focal-point' ); ?>">
					<a class="corsivo-admin-workspace__section" href="#contents" data-corsivo-section-link><?php esc_html_e( 'Punto focale', 'corsivo-focal-point' ); ?></a>
					<a class="corsivo-admin-workspace__section" href="#optimization" data-corsivo-section-link><?php esc_html_e( 'Ottimizzazione', 'corsivo-focal-point' ); ?></a>
					<a class="corsivo-admin-workspace__section" href="#formats" data-corsivo-section-link><?php esc_html_e( 'Formati', 'corsivo-focal-point' ); ?></a>
					<a class="corsivo-admin-workspace__section" href="#integrations" data-corsivo-section-link><?php esc_html_e( 'Integrazioni', 'corsivo-focal-point' ); ?></a>
					<a class="corsivo-admin-workspace__section" href="#data" data-corsivo-section-link><?php esc_html_e( 'Dati', 'corsivo-focal-point' ); ?></a>
				</nav>
				<div class="corsivo-admin-workspace__content">
			<section id="focal-section-contents" data-corsivo-section="contents" aria-labelledby="focal-contents-title">

			<h2 id="focal-contents-title"><?php echo esc_html__( 'Punto focale', 'corsivo-focal-point' ); ?></h2>
			<p><?php esc_html_e( 'Scegli il soggetto da mantenere visibile nelle immagini in evidenza. Ogni contenuto conserva la propria inquadratura.', 'corsivo-focal-point' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Modulo punto focale', 'corsivo-focal-point' ); ?></th>
					<td><label>
						<input type="hidden" name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[focal_point_enabled]" value="0">
						<input type="checkbox" name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[focal_point_enabled]" value="1" <?php checked( $settings['focal_point_enabled'] ); ?>>
						<?php esc_html_e( 'Abilita la scelta del punto focale', 'corsivo-focal-point' ); ?>
					</label><p class="description"><?php esc_html_e( 'Disattivandolo conservi le coordinate già salvate.', 'corsivo-focal-point' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Post type abilitati', 'corsivo-focal-point' ); ?></th>
					<td>
						<fieldset>
						<?php foreach ( $post_types as $post_type ) : ?>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[post_types][]"
									value="<?php echo esc_attr( $post_type->name ); ?>"
									<?php checked( in_array( $post_type->name, $settings['post_types'], true ) ); ?>
								>
								<?php echo esc_html( $post_type->labels->name ); ?>
								<code><?php echo esc_html( $post_type->name ); ?></code>
							</label>
							<br>
						<?php endforeach; ?>
						<p class="description"><?php echo esc_html__( 'Sono elencati solo i post type con interfaccia amministrativa e immagine in evidenza.', 'corsivo-focal-point' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>

			</section>
			<?php corsivo_media_render_upload_settings( $settings ); ?>
			<section id="focal-section-integrations" data-corsivo-section="integrations" aria-labelledby="focal-integrations-title">
			<h2 id="focal-integrations-title"><?php echo esc_html__( 'Integrazioni', 'corsivo-focal-point' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">WooCommerce</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[woocommerce_enabled]"
								value="1"
								<?php checked( $settings['woocommerce_enabled'] ); ?>
							>
							<?php echo esc_html__( 'Abilita prodotti e loop catalogo; applica la gallery principale solo ai prodotti non variabili', 'corsivo-focal-point' ); ?>
						</label>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_woocommerce_active()
									? __( 'WooCommerce rilevato.', 'corsivo-focal-point' )
									: __( 'Il modulo resta inattivo finché WooCommerce non è disponibile.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">WPML</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[wpml_copy_once_enabled]"
								value="1"
								<?php checked( $settings['wpml_copy_once_enabled'] ); ?>
							>
							<?php echo esc_html__( 'Copia una volta le coordinate nelle traduzioni che non hanno ancora un focal point', 'corsivo-focal-point' ); ?>
						</label>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_wpml_active()
									? __( 'WPML rilevato. Ogni traduzione resta poi modificabile in modo indipendente.', 'corsivo-focal-point' )
									: __( 'Il modulo resta inattivo finché WPML non è disponibile.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Yoast SEO</th>
					<td>
						<p class="description">
							<?php
							echo esc_html(
								corsivo_focal_point_is_yoast_active()
									? __( 'Rilevato. Non richiede un modulo: le coordinate CSS non modificano i file immagine usati nei meta social.', 'corsivo-focal-point' )
									: __( 'Nessun modulo necessario: le coordinate CSS non modificano i file immagine usati nei meta social.', 'corsivo-focal-point' )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			</section>
			<section id="focal-section-data" data-corsivo-section="data" aria-labelledby="focal-data-title">
			<h2 id="focal-data-title"><?php echo esc_html__( 'Dati', 'corsivo-focal-point' ); ?></h2>
			<p><?php esc_html_e( 'Le immagini elaborate e i relativi originali rimangono nella libreria anche disattivando o disinstallando il plugin.', 'corsivo-focal-point' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Storico', 'corsivo-focal-point' ); ?></th>
					<td><p class="description"><?php echo esc_html__( 'Le coordinate seguono le revisioni native e compaiono nel confronto quando il post type le supporta. Il ripristino non cambia la featured image: coordinate riferite a un altro media restano inattive.', 'corsivo-focal-point' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Disinstallazione', 'corsivo-focal-point' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( CORSIVO_FOCAL_POINT_SETTINGS_OPTION ); ?>[delete_on_uninstall]"
								value="1"
								<?php checked( $settings['delete_on_uninstall'] ); ?>
							>
							<?php echo esc_html__( 'Elimina coordinate e impostazioni quando il plugin viene disinstallato', 'corsivo-focal-point' ); ?>
						</label>
					</td>
				</tr>
			</table>

			</section>
			<p class="description"><?php esc_html_e( 'Il salvataggio comprende tutte le sezioni. Le immagini già presenti nella libreria non vengono rielaborate.', 'corsivo-focal-point' ); ?></p>
			<?php submit_button( __( 'Salva impostazioni media', 'corsivo-focal-point' ) ); ?>
				</div>
			</div>
		</form>
	</div>
	<?php
}

function corsivo_focal_point_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'upload.php?page=corsivo-media' ) ) . '">' . esc_html__( 'Impostazioni', 'corsivo-focal-point' ) . '</a>'
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( CORSIVO_FOCAL_POINT_FILE ), 'corsivo_focal_point_plugin_action_links' );

function corsivo_focal_point_activate_site() {
	corsivo_focal_point_initialize_site();
}

function corsivo_focal_point_activate( $network_wide = false ) {
	if ( ! is_multisite() || ! $network_wide ) {
		corsivo_focal_point_activate_site();
		return;
	}

	$offset = 0;
	$limit  = 100;

	do {
		$site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => $limit,
				'offset'  => $offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			try {
				corsivo_focal_point_activate_site();
			} finally {
				restore_current_blog();
			}
		}

		$offset += count( $site_ids );
	} while ( $limit === count( $site_ids ) );
}
