# Corsivo Media

Punto focale per le immagini in evidenza e ottimizzazione dei nuovi caricamenti JPEG/PNG: qualità, dimensioni massime e conversione WebP. I moduli sono indipendenti e non richiedono altri plugin Corsivo.

## Scopo e confini

La pagina **Media → Impostazioni media** (`upload.php?page=corsivo-media`) usa il workspace Corsivo con cinque sezioni laterali: Punto focale, Ottimizzazione, Formati, Integrazioni e Dati. Tutti i campi restano nello stesso form: cambiare sezione non perde le modifiche e il salvataggio comprende l'intera configurazione. Gli asset del workspace sono inclusi nel plugin. I precedenti collegamenti a `corsivo-focal-point` reindirizzano alla nuova pagina.

Il test `node tests/admin-workspace.cjs` (con jsdom disponibile nel percorso dei moduli Node) verifica pannelli, conservazione dei valori, invio completo, avviso sulle modifiche e visibilità dei campi non validi.

Il modulo Punto focale conserva il soggetto importante quando una featured image viene mostrata con `object-fit: cover`. Questo modulo non genera crop e non modifica i file: aggiunge `object-position` al markup già prodotto da WordPress o dalle integrazioni abilitate. Può essere disattivato senza cancellare le coordinate.

Il dato appartiene al post, ma è legato all'ID dell'immagine in evidenza corrente. Se la featured image cambia, le vecchie coordinate restano persistite ma inattive finché l'editor non salva una nuova associazione. Il valore centrale 50/50 è il default e non aggiunge stile al frontend.

## Ottimizzazione dei nuovi media

Ottimizzazione e WebP sono inizialmente disattivati, anche aggiornando un sito con la precedente versione Focal Point. Si abilitano separatamente nelle impostazioni:

- **Ottimizzazione:** qualità JPEG/WebP da 40 a 100 (default 82), lato più lungo da 256 a 8192 pixel (default 2560), senza ingrandimento.
- **WebP:** conversione dei nuovi JPEG/PNG statici se il server dispone di un encoder compatibile. Può essere usata da sola, mantenendo la qualità predefinita di WordPress e il comportamento core sulle dimensioni.
- **Originali:** sempre conservati. Le versioni elaborate possono aggiungere spazio su disco; non viene promesso un risparmio in ogni conversione.
- **Archivio esistente:** nessuna scansione, coda o conversione retroattiva. Modificare le impostazioni non rielabora gli allegati esistenti, neppure durante una rigenerazione delle miniature.
- **Formati esclusi:** GIF, SVG, immagini animate e formati diversi da JPEG/PNG; PNG conservati in PNG restano lossless e mantengono il canale alpha.

La directory, il file principale, il text domain, l'option, i meta e le API `corsivo_focal_point_*` mantengono l'identità tecnica precedente. Anche `Update URI` rimane sul canale esistente: il nome visibile Corsivo Media è un'evoluzione compatibile dello stesso plugin, non una seconda installazione.

### Flusso dei nuovi caricamenti

`includes/media-processing.php` registra i file appena passati da `wp_handle_upload` (upload e sideload) e li associa agli ID appena inseriti tramite `add_attachment`. Il contesto esiste soltanto nella richiesta corrente e viene consumato alla prima generazione dei metadata: il solo argomento `create` di `wp_generate_attachment_metadata` non è sufficiente per autorizzare un'elaborazione, perché WordPress lo usa anche nelle rigenerazioni.

Il motore usa `big_image_size_threshold`, `image_editor_output_format` e `wp_editor_set_quality` soltanto nel contesto autorizzato. WordPress genera full e miniature e conserva `original_image`. Per un JPEG piccolo che richiede solo qualità viene prodotta una copia `-optimized`, adottata soltanto se più leggera. I PNG animati sono esclusi leggendo il chunk `acTL`; il ridimensionamento PNG conserva la trasparenza. Non ci sono nuove tabelle, option di coda o meta proprietari del processo.

Se un WebP omonimo o un suo derivato esiste già, la conversione di quel caricamento viene saltata per preservare i file precedenti. Se l'encoder non è disponibile o una scrittura fallisce, si conserva il risultato valido disponibile nel formato originale. Non vengono sostituiti URL salvati nei contenuti esistenti.

Con Ottimizzazione o WebP attivi, `wp_client_side_media_processing_enabled` è impostato a `false`: su WordPress 7.1 l'elaborazione passa al server per conservare l'originale e verificare la provenienza del nuovo upload. Il filtro core è globale e non riceve il MIME del singolo file. Con entrambi i moduli spenti il comportamento del core rimane invariato. Le richieste REST esplicitamente già gestite dal client (`generate_sub_sizes=false` o `convert_format=false`) vengono lasciate al loro flusso; riaprire l'editor dopo aver attivato i moduli permette di ricevere le nuove impostazioni.

Questa prima versione gestisce file locali tramite gli editor WordPress. Pipeline di offload/CDN o ottimizzatori aggiuntivi richiedono verifica di compatibilità; l'importazione di un file tramite un attachment costruito manualmente senza il passaggio upload/sideload non viene elaborata. Disattivazione e disinstallazione non cancellano originali o immagini elaborate.

## Requisiti e dipendenze

Richiede WordPress 6.6+ e PHP 8.1+. Usa `@corsivo-suite/wp-admin-ui/focal-point-control` 0.1.0, basato sul `FocalPointPicker` core, oltre a `register_post_meta`, revisioni/autosave, data store dell'editor, media frame, `WP_HTML_Tag_Processor` e Settings API. La dipendenza Corsivo è risolta esclusivamente durante la build e non richiede il plugin showcase a runtime.

WooCommerce e WPML sono integrazioni facoltative e devono essere abilitate nelle impostazioni. Yoast non richiede un modulo: il focal point è CSS e non cambia l'immagine usata nei meta social.

## Bootstrap e lifecycle

`corsivo-focal-point.php` definisce versione e chiavi meta, carica settings, media processing, data, editor, frontend e integrazioni, quindi registra l'activation hook. Su `plugins_loaded` a priorità 5 viene creata l'option predefinita se manca; l'attivazione esegue lo stesso setup sul sito corrente o, in network activation, su tutti i blog in batch da 100 con `switch_to_blog()`.

Non registra cron o operazioni di deactivation. La disinstallazione è conservativa per default. Se `delete_on_uninstall` è attivo, `uninstall.php` attraversa ogni sito, elimina le quattro chiavi meta con verifica fisica, quindi elimina e verifica l'option; un errore interrompe il cleanup con eccezione.

## Architettura

`settings.php` gestisce option e pagina Impostazioni. `data.php` è il dominio: post type, sanitizzazione, letture, scrittura coordinata, revisioni e autosave. `editor.php` decide block/classic editor, registra pannello/meta box e coordina i save REST e form. `frontend.php` riscrive il primo `img`. `integrations.php` aggancia WooCommerce e implementa la policy WPML copy-once.

`admin-app` compila due entry JavaScript pure: `editor.js` monta un `PluginDocumentSettingPanel`; `classic-editor.js` monta lo stesso controllo nel meta box e osserva il cambio featured image. Il loader PHP accetta soltanto build complete con manifest, JS, CSS LTR e CSS RTL; in caso contrario conserva il fallback legacy sotto `assets/`.

## Storage e contratti dati

Non ci sono tabelle custom.

| Record | Tipo | Contratto |
|---|---|---|
| `_corsivo_focal_point_x` | post meta integer | Coordinata 0–100, default 50. |
| `_corsivo_focal_point_y` | post meta integer | Coordinata 0–100, default 50. |
| `_corsivo_focal_point_attachment_id` | post meta integer | ID attachment cui si riferiscono le coordinate; default 0. |
| `_corsivo_focal_point_revision_version` | revision meta | Marker `1:<sha256>` usato per attestare uno snapshot completo. |
| `corsivo_focal_point_settings` | option autoload | `focal_point_enabled`, `post_types`, `woocommerce_enabled`, `wpml_copy_once_enabled`, `delete_on_uninstall`, `optimization_enabled`, `image_quality`, `max_dimension`, `webp_enabled`. |

I tre meta pubblici sono `single`, `integer`, esposti nel REST core e autorizzati con `edit_post` sul contenuto. Le coordinate vengono arrotondate e clampate; input non numerico o infinito torna a 50. `corsivo_focal_point_update_position()` deduplica eventuali righe multiple, applica i tre valori e tenta un rollback verificato se una scrittura fallisce.

Revisioni e autosave includono anche il valore zero. Il marker lega coordinate e attachment in un solo snapshot: restore incompleti o alterati vengono rifiutati. La featured image non è versionata da WordPress e non viene ripristinata.

## Flussi end-to-end

Block editor: PHP registra i meta e aggiunge `custom-fields` solo ai post type abilitati. Il pannello legge `meta` e `featured_media` dal data store, risolve l'attachment via core-data, mostra `FocalPointPicker` e salva atomically i tre valori nel payload del post. Un cambio immagine rende irrilevante la vecchia associazione.

Classic editor: il meta box emette nonce, stato e fallback. Il componente React osserva `#postimagediv` con `MutationObserver`, mantiene una cache di coordinate per attachment durante la sessione e invia hidden input solo quando serve. `save_post` verifica nonce, capability, post type, autosave/revision e corrispondenza dell'attachment prima della scrittura coordinata.

Frontend: `post_thumbnail_html` controlla post type, presenza del focal point e legame con la featured image; `WP_HTML_Tag_Processor` trova il primo `IMG`, conserva gli altri stili, rimuove un precedente `object-position` e inserisce il valore corrente. Markup senza immagini o posizione centrale resta invariato.

Revisioni/REST: hook dinamici `rest_insert_{post_type}` e `rest_after_insert_{post_type}` catturano lo stato precedente, eseguono l'eventuale copy-once WPML e notificano un cambiamento effettivo. Il restore revisioni riporta insieme i meta solo se il marker è valido.

## Entry point REST, AJAX, hook, cron e CLI

Non esistono route REST custom: il contratto remoto è il meta schema delle route core dei post type abilitati. Non esistono endpoint AJAX, cron, shortcode o comandi WP-CLI.

Hook WordPress principali: `init` registra meta e hook REST dinamici; `enqueue_block_editor_assets`, `add_meta_boxes`, `admin_enqueue_scripts` e `save_post` gestiscono gli editor; `post_thumbnail_html` gestisce il frontend; filtri/action WooCommerce e WPML sono registrati solo a integrazione attiva.

API e extension hook pubblici: filtro `corsivo_focal_point_post_types`; action `corsivo_focal_point_position_updated`; action diagnostica `corsivo_focal_point_failure`. Funzioni riusabili: `corsivo_focal_point_get_state()`, `corsivo_focal_point_get_position_array()`, `corsivo_focal_point_get_position()` e `corsivo_focal_point_update_position()`.

## Admin, editor e frontend

Media → Corsivo Media, nella sezione Punto focale, elenca solo post type con UI e supporto thumbnail; `product` non è selezionabile direttamente e passa dal toggle WooCommerce. La pagina espone anche WPML copy-once e cleanup alla disinstallazione.

Nel block editor il pannello è document-level. Nel classic editor il meta box laterale riusa componenti WordPress; notice transitorie segnalano cambio attachment, fallimento scrittura o restore incompleto. Sul frontend non vengono caricati CSS o JavaScript: lo stile è inline sul primo `img` del markup filtrato.

## Integrazioni

WooCommerce, se abilitato e attivo, aggiunge `product` ai post type gestiti. Il filtro viene applicato alle immagini di catalogo, alla sola immagine principale della gallery classica e al blocco `woocommerce/product-image`. Le gallery dei prodotti variabili vengono saltate perché WooCommerce sostituisce la sorgente mantenendo gli stili.

WPML, se abilitato, cerca l'unica traduzione originale del TRID. Una traduzione senza coordinate riceve una sola volta x/y della sorgente, associandole però alla propria featured image; poi resta indipendente. La copia viene tentata su save, cambio `_thumbnail_id`, REST e traduzione automatica completata. `wpml-config.xml` imposta i meta su `ignore` per evitare sincronizzazioni generiche successive.

## Sicurezza, concorrenza ed error handling

Ogni scrittura editoriale verifica `edit_post`; la pagina impostazioni richiede `manage_options`; il classic editor verifica nonce e rifiuta payload non scalari. La scrittura coordinata conserva lo snapshot raw dei meta, deduplica, rilegge e tenta rollback. Non usa lock DB: protegge coerenza interna e cambio featured image, ma due salvataggi realmente concorrenti restano soggetti all'ordine delle write WordPress.

Gli errori applicativi vengono serializzati nell'error log e inviati a `corsivo_focal_point_failure`. I salvataggi UI mostrano notice best-effort. Il frontend fallisce aperto sul markup originale se post, stato, attachment o tag `img` non sono utilizzabili.

## Configurazione

L'option predefinita abilita soltanto `post`. Il filtro `corsivo_focal_point_post_types` consente ai site plugin di aggiungere CPT, ma il risultato finale deve esistere e supportare thumbnail. WooCommerce, WPML e cancellazione dati sono opt-in. L'header `Update URI` punta a `https://plugins.corsivo.dev/corsivo-focal-point`.

## Build, lingue e test

La build editoriale del punto focale è autonoma; la nuova pagina impostazioni e il motore immagini sono PHP/JavaScript nativo e non richiedono una nuova build React:

```sh
cd admin-app
npm install
npm run check
```

`admin-app/build` contiene le due entry, i manifest e le varianti CSS LTR/RTL. `assets/editor.js`, `assets/classic-editor.js` e `assets/editor.css` restano fallback runtime. Il working tree non contiene `.po`, `.mo`, `.pot` o JSON di traduzione; `wp_set_script_translations()` usa il text domain, ma non c'è un catalogo locale distribuito.

Con plugin attivo e WordPress caricato:

```sh
wp eval-file wp-content/plugins/corsivo-focal-point/tests/smoke.php
```

Lo smoke copre sanitizzazione, rendering HTML, schema REST, deduplica/rollback, autosave, integrità e restore revisioni, WPML copy-once e diagnostica. Crea contenuti temporanei e fallisce con `RuntimeException` se trova errori. `.distignore` esclude `tests/` dal pacchetto.

Senza WordPress, `php tests/data-regressions.php` verifica arrotondamento, limiti e input non validi delle coordinate, inclusi numeri finiti maggiori del range degli interi PHP. Il clamp precede la conversione a intero per evitare che un overflow sposti il focal point sul bordo opposto.

La nuova gestione media si verifica senza database:

```sh
php tests/admin-navigation.php
php tests/media-settings.php
php tests/media-processing-real.php
```

L'ultimo harness richiede GD e una copia locale del core WordPress: usa realmente `wp_generate_attachment_metadata`, `wp_create_image_subsizes` e `WP_Image_Editor_GD` su fixture temporanee con storage degli allegati in memoria. Verifica copie full, miniature, dimensioni, WebP, alpha PNG, originali byte-identici, vecchi media, esclusione APNG/client, collisioni e guasti di encoder/scrittura. Non carica `wp-load.php`, non apre il database e non carica file nella libreria del sito.

## Extension point

Il filtro `corsivo_focal_point_post_types` è il punto corretto per abilitare CPT di progetto. Per template con background o markup non prodotto da `post_thumbnail_html`, usare le funzioni getter e applicare esplicitamente la stringa restituita. Per scritture programmatiche usare `corsivo_focal_point_update_position()` e, se il consumer deve reagire, emettere o ascoltare `corsivo_focal_point_position_updated` rispettando lo stato precedente.

## Limiti reali

Il modulo Punto focale non genera crop e non interviene direttamente sui meta Open Graph/Twitter. Applica la posizione solo al primo `img` del markup filtrato. Il restore non ripristina la featured image. Il fallback WPML è copy-once, non sincronizzazione continua. Il cleanup globale dei meta può essere costoso su database grandi ed è eseguito solo in uninstall esplicito. La documentazione Apache in `.htaccess` protegge dotfile/archivi solo su server che la interpretano.

## Inventario file

- `README.md` — contratto operativo corrente di dati focal point, editor, rendering, integrazioni e inventario.
- `.distignore` — esclusioni del pacchetto, inclusi test e file di sviluppo.
- `.gitignore` — esclusione degli ZIP locali.
- `.htaccess` — regole Apache per negare dotfile, archivi e artefatti sensibili.
- `corsivo-focal-point.php` — header, costanti, require e activation hook.
- `changelog.txt` — storia delle release fino alla 1.2.2.
- `LICENSE` — testo GPL-2.0.
- `uninstall.php` — cleanup opt-in, multisite e verificato.
- `wpml-config.xml` — policy WPML `ignore` per i meta focal point.
- `includes/settings.php` — default, sanitizzazione, Settings API, pagina admin e attivazione multisite.
- `includes/media-processing.php` - contesto dei nuovi upload, qualità/dimensioni/WebP, conservazione originali e compatibilità con il flusso client.
- `assets/media-settings.css` - adattamento dei nuovi controlli nella pagina Media.
- `tests/media-settings.php` - regressioni delle nuove impostazioni e compatibilità dei dati precedenti.
- `tests/media-processing-real.php` - elaborazione di immagini reali con core/GD e storage in memoria.
- `includes/data.php` — post type, meta, API pubbliche, scrittura coordinata, revisioni e autosave.
- `includes/editor.php` — block/classic editor, nonce, asset, REST lifecycle e notice.
- `includes/frontend.php` — riscrittura `object-position` con Tag Processor.
- `includes/integrations.php` — WooCommerce e WPML copy-once.
- `admin-app/package.json` e `package-lock.json` — pipeline privata e dipendenza locale da Corsivo UI.
- `admin-app/src/editor.js` — pannello Gutenberg con `FocalPointControl`.
- `admin-app/src/classic-editor.js` — editor classic, cache per attachment e MutationObserver.
- `admin-app/src/focal-point-adapter.js` — conversione controllata `0–1` ↔ storage `0–100`.
- `admin-app/build/` — bundle, manifest e CSS LTR/RTL distribuiti.
- `assets/editor.js` — fallback Gutenberg legacy.
- `assets/classic-editor.js` — fallback classic legacy.
- `assets/editor.css` — minime correzioni layout condivise dai due editor.
- `tests/smoke.php` — smoke WP-CLI end-to-end.
- `tests/data-regressions.php` — regressioni isolate sulla sanitizzazione delle coordinate.
- `tests/admin-assets-smoke.php` — gate build, manifest, dipendenze e RTL.

Non sono presenti `node_modules/`, `vendor/` o `libs/tcpdf/`; non ci sono dipendenze terze da raggruppare.
