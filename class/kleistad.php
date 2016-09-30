<?php
/*
  Class: Kleistad
  Description: Basis klas voor kleistad_reserveren plugin
  Version: 1.1
  Author: Eric Sprangers
  Author URI:
  License: GPL2
 */

/* versie historie
 *
 * 1.0  18-04-2016  Eerste baseline
 * 1.1  19-04-2016  Mogelijkheid toegevoegd om wijzigingen in reserveringen door
 *                  te voeren mits redactie bevoegdheid,
 *                  code optimalisatie van muteren functie
 * 2.0  12-09-2016  Toevoegen functionaliteit voor beheer stooksaldo (leden), tonen saldo overzicht (bestuur), verzenden stookbestand (bestuur)
 *
 */
defined('ABSPATH') or die("No script kiddies please!");

class Kleistad {

    /**
     * @var Kleistad instance
     */
    protected static $instance = NULL;

    /**
     * get_instance maakt de plugin object instance aan
     * @return type Kleistad
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Custom capabilities
     */
    const OVERRIDE = 'kleistad_reserveer_voor_ander';
    const RESERVEER = 'kleistad_reservering_aanmaken';

    /**
     * Plugin-versie
     */
    const VERSIE = 2;

    /**
     * Aantal dagen tussen reserveer datum en saldo verwerkings datum
     */
    const TERMIJN = 4;

    /**
     *
     * @var string url voor Ajax callbacks 
     */
    private $url;

    /**
     *
     * @var string email adres van Kleistad
     */
    private $from_email;

    /**
     *
     * @var type email adres om berichten naar toe te sturen
     */
    private $info_email;

    /**
     *
     * @var type email adres om copy van elk bericht op te ontvangen per bcc
     */
    private $copy_email;

    /**
     * constructor, alleen registratie van acties
     */
    public function __construct() {
        $domein = substr(strrchr(get_option('admin_email'), '@'), 1);
        $this->url = 'kleistad_reserveren/v' . self::VERSIE;
        $this->info_email = 'info@' . $domein;
        $this->from_email = 'no-reply@' . $domein;
        $this->copy_email = 'stook@' . $domein;
    }

    /**
     * Initialiseer de plugin
     */
    public function setup() {
        if (is_admin()) {
            add_action('admin_menu', function () {
                add_options_page('Kleistad reserveringen', 'Instellingen Kleistad reserveringen', 'manage_options', 'kleistad-reserveren', [$this, 'instellingen']);
            });
        } else {
            add_action('rest_api_init', [$this, 'register_endpoints']);
            add_action('wp_enqueue_scripts', [$this, 'register_scripts']);
            add_action('kleistad_kosten', [$this, 'update_ovenkosten']);

            add_filter('widget_text', 'do_shortcode');

            add_shortcode('kleistad_rapport', [$this, 'rapport_handler']);
            add_shortcode('kleistad_saldo', [$this, 'saldo_handler']);
            add_shortcode('kleistad_saldo_overzicht', [$this, 'saldo_overzicht_handler']);
            add_shortcode('kleistad_stookbestand', [$this, 'stookbestand_handler']);
            add_shortcode('kleistad', [$this, 'reservering_handler']);
        }
    }

    /**
     * 
     * @global type $wpdbdatabase tabellen aanmaken of aanpassen, alleen bij activering plugin
     */
    private static function database() {
        $database_version = intval(get_option('kleistad-reserveren', 0));
        if ($database_version < self::VERSIE) {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();

            flush_rewrite_rules();
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta("CREATE TABLE {$wpdb->prefix}kleistad_reserveringen (
                id int(10) NOT NULL AUTO_INCREMENT,
                oven_id smallint(4) NOT NULL,
                jaar smallint(4) NOT NULL,
                maand tinyint(2) NOT NULL,
                dag tinyint(1) NOT NULL,
                gebruiker_id int(10) NOT NULL,
                temperatuur int(10),
                soortstook tinytext,
                programma smallint(4),
                gemeld tinyint(1) DEFAULT 0,
                verwerkt tinyint(1) DEFAULT 0,
                verdeling tinytext,
                opmerking tinytext,
                PRIMARY KEY  (id)
                ) $charset_collate;"
            );

            dbDelta("CREATE TABLE {$wpdb->prefix}kleistad_ovens (
                id int(10) NOT NULL AUTO_INCREMENT,
                naam tinytext,
                kosten numeric(10,2),
                PRIMARY KEY  (id)
                ) $charset_collate;"
            );
            update_option('kleistad-reserveren', self::VERSIE);
        }
    }

    /**
     * activeer plugin
     */
    public static function activate() {
        self::database();

        if (!wp_next_scheduled('kleistad_kosten')) {
            wp_schedule_event(strtotime('midnight'), 'daily', 'kleistad_kosten');
        }

        /*
         * n.b. in principe heeft de (toekomstige) rol bestuurde de override capability en de (toekomstige) rol lid de reserve capability
         * zolang die rollen nog niet gedefinieerd zijn hanteren we de onderstaande toekenning
         */
        global $wp_roles;

        $wp_roles->add_cap('administrator', self::OVERRIDE);
        $wp_roles->add_cap('editor', self::OVERRIDE);
        $wp_roles->add_cap('author', self::OVERRIDE);

        $wp_roles->add_cap('administrator', self::RESERVEER);
        $wp_roles->add_cap('editor', self::RESERVEER);
        $wp_roles->add_cap('author', self::RESERVEER);
        $wp_roles->add_cap('contributor', self::RESERVEER);
        $wp_roles->add_cap('subscriber', self::RESERVEER);
    }

    /**
     * deactiveer plugin
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('kleistad_kosten');

        global $wp_roles;
        /*
         * de rollen verwijderen bij deactivering van de plugin. Bij aanpassing rollen (zie activate) het onderstaande ook aanpassen.
         */
        $wp_roles->remove_cap('administrator', self::OVERRIDE);
        $wp_roles->remove_cap('editor', self::OVERRIDE);
        $wp_roles->remove_cap('author', self::OVERRIDE);

        $wp_roles->remove_cap('administrator', self::RESERVEER);
        $wp_roles->remove_cap('editor', self::RESERVEER);
        $wp_roles->remove_cap('author', self::RESERVEER);
        $wp_roles->remove_cap('contributor', self::RESERVEER);
        $wp_roles->remove_cap('subscriber', self::RESERVEER);
    }

    /**
     * registreer de AJAX endpoints
     */
    public function register_endpoints() {
        register_rest_route(
            $this->url, '/reserveer', [
            'methods' => 'POST',
            'callback' => [$this, 'callback_muteren'],
            'args' => [
                'dag' => ['required' => true],
                'maand' => ['required' => true],
                'jaar' => ['required' => true],
                'oven_id' => ['required' => true],
                'temperatuur' => ['required' => false],
                'soortstook' => ['required' => false],
                'programma' => ['required' => false],
                'verdeling' => ['required' => false],
                'opmerking' => ['required' => false],
                'gebruiker_id' => ['required' => true],
            ],
            'permission_callback' => function() {
            return is_user_logged_in();
        }
        ]);
        register_rest_route(
            $this->url, '/show', [
            'methods' => 'POST',
            'callback' => [$this, 'callback_show_reservering'],
            'args' => [
                'maand' => ['required' => true],
                'jaar' => ['required' => true],
                'oven_id' => ['required' => true],
                'html' => ['required' => false]
            ],
            'permission_callback' => function() {
            return is_user_logged_in();
        }
        ]);
    }

    /**
     * registreer de scripts
     */
    public function register_scripts() {
        wp_register_script(
            'kleistad-js', plugins_url('../js/kleistad.js', __FILE__), ['jquery'], self::VERSIE, true
        );
        wp_register_style(
            'kleistad-css', plugins_url('../css/kleistad.css', __FILE__), [], self::VERSIE
        );

        wp_localize_script(
            'kleistad-js', 'kleistad_data', [
            'nonce' => wp_create_nonce('wp_rest'),
            'base_url' => rest_url($this->url),
            'success_message' => 'de reservering is geslaagd!',
            'error_message' => 'het was niet mogelijk om de reservering uit te voeren'
            ]
        );
    }

    /**
     * 
     * admin instellingen scherm
     */
    public function instellingen() {
        global $wpdb;

        if (!is_null(filter_input(INPUT_POST, 'kleistad_ovens_verzonden'))) {
            $naam = filter_input(INPUT_POST, 'kleistad_oven_naam', FILTER_SANITIZE_SPECIAL_CHARS);
            $tarief = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_oven_tarief'));
            $wpdb->insert("{$wpdb->prefix}kleistad_ovens", ['naam' => $naam, 'kosten' => $tarief], ['%s', '%s']);
        }

        if (!is_null(filter_input(INPUT_POST, 'kleistad_regeling_verzonden'))) {
            $gebruiker_id = filter_input(INPUT_POST, 'kleistad_regeling_gebruiker_id', FILTER_VALIDATE_INT);
            $oven_id = filter_input(INPUT_POST, 'kleistad_regeling_id', FILTER_VALIDATE_INT);
            $tarief = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_regeling_tarief', FILTER_SANITIZE_SPECIAL_CHARS));
            $this->maak_regeling($gebruiker_id, $oven_id, $tarief);
        }

        if (!is_null(filter_input(INPUT_POST, 'kleistad_saldo_verzonden'))) {
            $gebruiker_id = filter_input(INPUT_POST, 'kleistad_saldo_gebruiker_id', FILTER_VALIDATE_INT);
            $saldo = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_saldo_wijzigen', FILTER_SANITIZE_SPECIAL_CHARS));
            update_user_meta($gebruiker_id, 'stooksaldo', $saldo);
        }
        /*
        if (!is_null(filter_input(INPUT_POST, 'kleistad_import_verzonden'))) {
            $bestand = $_FILES['kleistad_import_bestand'];
            $upload_dir = wp_upload_dir();
            $importeren = filter_input(INPUT_POST, 'kleistad_importeren') != 'false';
            if ($bestand['error'] == UPLOAD_ERR_OK) {
                move_uploaded_file($bestand['tmp_name'], $upload_dir['basedir'] . "/{$bestand['name']}");
            }
            $this->import($bestand['name'], $importeren);
        }
        */
        $ovens = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}kleistad_ovens ORDER BY id");
        $gebruikers = get_users(
            ['fields' => ['id', 'display_name'], 'orderby' => ['nicename'],]);
        ?>
        <div class="wrap">
            <h2>Regelingen</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th class="manage-column column-naamregeling" id="naamregeling" scope="col">Naam</th>
                        <th class="manage-column column-idregeling" id="idregeling" scope="col">Oven id</th>
                        <th class="manage-column column-tariefregeling" id="tariefregeling" scope="col">Tarief</th></tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($gebruikers as $gebruiker) {
                        $regelingen = $this->lees_regeling($gebruiker->id);
                        if ($regelingen < 0 ) {
                            continue;
                        }
                        foreach ($regelingen as $id => $regeling) {
                            ?>
                            <tr><td><?php echo $gebruiker->display_name ?></td><td><?php echo $id ?></td><td>&euro; <?php echo number_format($regeling, 2, ',', '') ?></td></tr>
                        <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
            <h3>Nieuwe regeling aanmaken of bestaande wijzigen</h3> 
            <form action="<?php echo get_permalink() ?>" method="POST">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="kleistad_regeling_id">Oven id</label></th>
                            <td><input type="number" name="kleistad_regeling_id" id="kleistad_regeling_id" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kleistad_regeling_gebruiker_id">Gebruiker</label></th>
                            <td><select name="kleistad_regeling_gebruiker_id" id="kleistad_regeling_gebruiker_id" >
                                    <?php foreach ($gebruikers as $gebruiker) : ?>
                                        <option value="<?php echo $gebruiker->id ?>" ><?php echo $gebruiker->display_name ?></option>
        <?php endforeach ?>
                                </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kleistad_regeling_tarief">Tarief</label></th>
                            <td><input type="number" step="any" name="kleistad_regeling_tarief" id="kleistad_regeling_tarief" /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit"><button type="submit" class="button-primary" name="kleistad_regeling_verzonden" id="kleistad_regeling_verzonden">Verzenden</button></p>
            </form>
            <hr />
            <h2>Ovens</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th class="manage-column column-idovens" id="idovens" scope="col">Oven id</th>
                        <th class="manage-column column-naamvens" id="naamovens" scope="col">Naam</th>
                        <th class="manage-column column-tariefovens" id="tariefovens">Tarief</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ovens as $oven) : ?>
                        <tr><td><?php echo $oven->id ?></td><td><?php echo $oven->naam ?></td><td>&euro; <?php echo number_format($oven->kosten, 2, ',', '') ?></td></tr>
        <?php endforeach ?>
                </tbody>
            </table>
            <h3>Nieuwe oven aanmaken</h3> 
            <form class="kleistad_form" action="<?php echo get_permalink() ?>" method="POST" >
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="kleistad_oven_naam" >Naam</label></th>
                            <td><input type="text" maxlength="30" name="kleistad_oven_naam" id="kleistad_oven_naam" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kleistad_oven_tarief" >Tarief</label></th>
                            <td><input type="number" step="any" name="kleistad_oven_tarief" id="kleistad_oven_tarief" /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit"><button type="submit" class="button-primary" name="kleistad_ovens_verzonden" id="kleistad_ovens_verzonden">Verzenden</button></p>
            </form>
            <hr />
            <h2>Saldo</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th class="manage-column column-naamsaldo" id="naamsaldo" scope="col">Naam</th>
                        <th class="manage-column column-wijzigingsaldo" id="wijzigingsaldo" scope="col">Saldo</th></tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($gebruikers as $gebruiker) {
                        $huidig = get_user_meta($gebruiker->id, 'stooksaldo', true);
                        if ($huidig <> '') {
                            ?>
                            <tr><td><?php echo $gebruiker->display_name ?></td><td>&euro; <?php echo number_format((float) $huidig, 2, ',', '') ?></td></tr>
                        <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
            <h3>Saldo wijzigen</h3> 
            <form action="<?php echo get_permalink() ?>" method="POST">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="kleistad_saldo_gebruiker_id">Gebruiker</label></th>
                            <td><select name="kleistad_saldo_gebruiker_id" id="kleistad_saldo_gebruiker_id" >
                                    <?php foreach ($gebruikers as $gebruiker) : ?>
                                        <option value="<?php echo $gebruiker->id ?>" ><?php echo $gebruiker->display_name ?></option>
        <?php endforeach ?>
                                </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="kleistad_saldo_wijzigen">Tarief</label></th>
                            <td><input type="number" step="any" name="kleistad_saldo_wijzigen" id="kleistad_saldo_wijzigen" /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit"><button type="submit" class="button-primary" name="kleistad_saldo_verzonden" id="kleistad_saldo_verzonden">Verzenden</button></p>
            </form>
<!--            <hr />
            <h2>Importeren</h2> 
            <form action="< ?php echo get_permalink() ? >" method="POST" enctype="multipart/form-data" encoding="multipart/form-data" >
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="kleistad_import_bestand" >Bestand</label></th>
                            <td><input type="file" name="kleistad_import_bestand" id="kleistad_import_bestand" /></td>
                            <th scope="row"><label for="kleistad_importeren" >Testen</label></th>
                            <td><input type="checkbox" name="kleistad_importeren" id="kleistad_importeren" value="false" checked /></td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit"><button type="submit" class="button-primary" name="kleistad_import_verzonden" id="kleistad_import_verzonden">Verzenden</button></p>
            </form>-->

        </div>
        <?php
    }

    /**
     * wrapper voor wp_mail functie
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param string $attachment
     */
    public function mail($to, $subject, $message, $copy = false, $attachment = '') {
        $headers[] = "From: Kleistad <$this->from_email>";
        $headers[] = "Bcc: $this->copy_email";
        if ($copy) {
            $headers[] = "Cc: $this->info_email";
        }
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        
        $htmlmessage = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
            <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <meta name="viewport" content="initial-scale=1.0"/>
            <meta name="format-detection" content="telephone=no"/>
            <title>' . $subject . '</title>
            </head>
            <body><table width="100%" border="0" align="center" cellpadding="0" cellspacing="0">
            <tr>
            <td align="left" style="font-family:helvetica; font-size:13pt" >' . preg_replace('/\s+/', ' ', $message) . '<br /><p>Met vriendelijke groet,</p>
            <p>Kleistad</p><p><a href="mailto:' . $this->info_email . '" target="_top">' . $this->info_email . '</a></p></td>                         
            </tr>
            <tr>
            <td align="center" style="font-family:calibri; font-size:9pt" >Deze e-mail is automatisch gegenereerd en kan niet beantwoord worden.</td>
            </tr></table></body>
            </html>';
        return wp_mail($to, $subject, $htmlmessage, $headers, $attachment);
    }

    /**
     * help functie, bestuursleden kunnen publiceren en mogen daarom aanpassen
     * @return bool
     */
    private function override() {
        return current_user_can(self::OVERRIDE);
    }

    /**
     * help functie, leden moeten kunnen reserveren en stooksaldo aanpassingen doen
     * 
     */
    private function reserveer() {
        return current_user_can(self::RESERVEER);
    }

    /**
     * help functie, lees mogelijke regeling
     * @return regeling waarde of false als niet bestaat of regeling array als oven_id afwezig/0
     */
    private function lees_regeling($gebruiker_id, $oven_id = 0) {
        $_ovenkosten = get_user_meta($gebruiker_id, 'ovenkosten', true);
        if ($_ovenkosten != '') {
            $ovenkosten = json_decode($_ovenkosten, true);
            if ($oven_id == 0) {
                return $ovenkosten;
            }
            if (array_key_exists($oven_id, $ovenkosten)) {
                return $ovenkosten[$oven_id];
            }
        }
        return -1;
    }

    /**
     * help functie, maak regeling
     * @return void
     */
    private function maak_regeling($gebruiker_id, $oven_id, $tarief) {
        $_ovenkosten = get_user_meta($gebruiker_id, 'ovenkosten', true);
        if ($_ovenkosten != '') {
            $ovenkosten = json_decode($_ovenkosten, true);
        } else {
            $ovenkosten = [];
        }
        $ovenkosten [$oven_id] = $tarief;
        update_user_meta($gebruiker_id, 'ovenkosten', json_encode($ovenkosten));
    }

    /**
     * help functie, log de tekstregel naar de saldo log
     * @param string $tekstregel
     */
    private function log_saldo($tekstregel) {
        $upload_dir = wp_upload_dir();
        $transactie_log = $upload_dir['basedir'] . '/stooksaldo.log';
        $f = fopen($transactie_log, 'a');
        $timestamp = date('c');
        fwrite($f, $timestamp . ': ' . $tekstregel . "\n");
        fclose($f);
    }

    /**
     * shortcode handler voor tonen van saldo van gebruikers [kleistad_saldo_overzicht]
     * 
     * @return string (html)
     */
    public function saldo_overzicht_handler() {
        if (!$this->override()) {
            return '';
        }
        wp_enqueue_style('kleistad-css');

        $gebruikers = get_users(['fields' => ['id', 'display_name'], 'orderby' => ['nicename']]);
        $html = '<table class="kleistad_rapport">
            <thead>
                <tr><th>Naam</th><th>Saldo</th></tr>
            </thead>
            <tbody>';
        foreach ($gebruikers as $gebruiker) {
            $saldo = number_format((float) get_user_meta($gebruiker->id, 'stooksaldo', true), 2, ',', '');
            $html .= "<tr><td>$gebruiker->display_name</td><td>&euro; $saldo</td></tr>";
        }
        $html .= '</tbody>
            </table>';
        return $html;
    }

    /**
     * shortcode handler voor tonen van rapporten [kleistad_rapport]
     * 
     * @return string (html)
     */
    public function rapport_handler() {
        if (!$this->reserveer()) {
            return '';
        }
        wp_enqueue_style('kleistad-css');

        $huidige_gebruiker = wp_get_current_user();
        $datum_begin = date('Y-m-d', strtotime('- 6 months')); // laatste half jaar
        $saldo = number_format((float) get_user_meta($huidige_gebruiker->ID, 'stooksaldo', true), 2, ',', '');
        
        global $wpdb;
        $reserveringen = $wpdb->get_results(
            "SELECT RE.id AS id, oven_id, naam, kosten, soortstook, temperatuur, programma,gebruiker_id, dag, maand, jaar, verdeling, verwerkt FROM
                {$wpdb->prefix}kleistad_reserveringen RE, {$wpdb->prefix}kleistad_ovens OV
            WHERE RE.oven_id = OV.id AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') > '$datum_begin' 
                    ORDER BY jaar, maand, dag ASC");
        $html = "<table class=\"kleistad_rapport\">
            <thead>
                <tr><th colspan=\"9\">Stookrapport voor $huidige_gebruiker->display_name (je huidig saldo is &euro; $saldo)</th></tr>
                <tr><th>Datum</th><th>Oven</th><th>Stoker</th><th>Stook</th><th>Temp</th><th>Prog</th><th>%</th><th>Kosten</th><th>Voorlopig</th></tr>
            </thead>
            <tbody>";
        foreach ($reserveringen as $reservering) {
            $stookdelen = json_decode($reservering->verdeling, true);
            foreach ($stookdelen as $stookdeel) {
                if (intval($stookdeel['id']) <> $huidige_gebruiker->ID) {
                    continue;
                }
                // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
                $regeling = $this->lees_regeling($huidige_gebruiker->ID, $reservering->oven_id);
                $kosten = number_format(round($stookdeel['perc'] / 100 * ( ( $regeling < 0) ? $reservering->kosten : $regeling ), 2), 2, ',', '');
                $stoker = get_userdata($reservering->gebruiker_id);
                $gereserveerd = $reservering->verwerkt != 1 ? '<span class="genericon genericon-checkmark"></span>' : '';
                $programma = is_null($reservering->programma) || $reservering->programma == 0 ? '' : $reservering->programma;
                $html .= "
                    <tr>
                        <td>$reservering->dag/$reservering->maand</td>
                        <td>$reservering->naam</td>
                        <td>$stoker->display_name</td>
                        <td>$reservering->soortstook</td>
                        <td>$reservering->temperatuur</td>
                        <td>$programma</td>
                        <td>{$stookdeel['perc']}</td>
                        <td>&euro; $kosten</td>
                        <td style=\"text-align:center\">$gereserveerd</td>
                    </tr>";
            }
        }
        $html .="</tbody>
            </table>";
        return $html;
    }

    /**
     * shortcode handler voor emailen van het CSV bestand met transacties [kleistad_stookbestand]
     * 
     * @return string (html)
     */
    public function stookbestand_handler() {
        if (!$this->override()) {
            return '';
        }
        wp_enqueue_style('kleistad-css');
        if (!is_null(filter_input(INPUT_POST, 'kleistad_stookbestand_verzonden'))) {
            $vanaf_datum = date('Y-m-d', strtotime(filter_input(INPUT_POST, 'kleistad_vanaf_datum')));
            $tot_datum = date('Y-m-d', strtotime(filter_input(INPUT_POST, 'kleistad_tot_datum')));
            $gebruiker = get_userdata(filter_input(INPUT_POST, 'kleistad_gebruiker_id', FILTER_VALIDATE_INT));

            $upload_dir = wp_upload_dir();
            $bijlage = $upload_dir['basedir'] . '/stookbestand_' . date('Y_m_d') . '.csv';
            //$bijlage = WP_CONTENT_DIR . '/uploads/stookbestand_' . date('Y_m_d') . '.csv';
            $f = fopen($bijlage, 'w');

            global $wpdb;
            $stoken = $wpdb->get_results(
                "SELECT RE.id AS id, oven_id, naam, kosten, soortstook, temperatuur, programma,gebruiker_id, dag, maand, jaar, verdeling, verwerkt FROM
                    {$wpdb->prefix}kleistad_reserveringen RE, {$wpdb->prefix}kleistad_ovens OV
                WHERE RE.oven_id = OV.id AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') BETWEEN '$vanaf_datum' AND '$tot_datum'
                        ORDER BY jaar, maand, dag ASC");
            $medestokers = [];
            foreach ($stoken as $stook) {
                if ($stook->verdeling == null) {
                    continue;
                }
                $stookdelen = json_decode($stook->verdeling, true);
                for ($i = 0; $i < 5; $i++) {
                    $medestoker_id = $stookdelen[$i]['id'];
                    if ($medestoker_id > 0) {
                        if (!array_key_exists($medestoker_id, $medestokers)) {
                            $medestoker = get_userdata($medestoker_id);
                            $medestokers[$medestoker_id] = $medestoker->display_name;
                        }
                    }
                }
            }
            asort($medestokers);
            $fields = ['Stoker', 'Datum', 'Oven', 'Kosten', 'Soort Stook', 'Temperatuur', 'Programma'];
            for ($i = 1; $i <= 2; $i++) {
                foreach ($medestokers as $medestoker) {
                    $fields[] = $medestoker;
                }
            }
            $fields[] = 'Totaal';
            fputcsv($f, $fields, ';', '"');

            foreach ($stoken as $stook) {
                $stoker = get_userdata($stook->gebruiker_id);
                $stookdelen = json_decode($stook->verdeling, true);
                $totaal = 0;
                $values = [$stoker->display_name, $stook->dag . '-' . $stook->maand . '-' . $stook->jaar, $stook->naam, number_format($stook->kosten, 2, ',', ''), $stook->soortstook, $stook->temperatuur, $stook->programma];
                foreach ($medestokers as $id => $medestoker) {
                    $percentage = 0;
                    for ($i = 0; $i < 5; $i ++) {
                        if ($stookdelen[$i]['id'] == $id) {
                            $percentage = $percentage + $stookdelen[$i]['perc'];
                        }
                    }
                    $values [] = ($percentage == 0) ? '' : $percentage;
                }
                foreach ($medestokers as $id => $medestoker) {
                    $percentage = 0;
                    for ($i = 0; $i < 5; $i ++) {
                        if ($stookdelen[$i]['id'] == $id) {
                            $percentage = $percentage + $stookdelen[$i]['perc'];
                        }
                    }
                    if ($percentage > 0) {
                        // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
                        $regeling = $this->lees_regeling($id, $stook->oven_id);
                        $kosten = round(($percentage * ( ( $regeling < 0 ) ? $stook->kosten : $regeling )) / 100, 2);
                        $totaal += $kosten;
                    }
                    $values [] = ($percentage == 0) ? '' : number_format($kosten, 2, ',', '');
                }
                $values [] = number_format($totaal, 2);
                fputcsv($f, $values, ';', '"');
            }

            fclose($f);

            $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
            $message = "<p>Bijgaand het bestand in .CSV formaat met alle transacties tussen $vanaf_datum en $tot_datum.</p>";
            $attachments = [ $bijlage];
            if ($this->mail($to, "Kleistad stookbestand $vanaf_datum - $tot_datum", $message, false, $attachments)) {
                $html = '<div><p>Het bestand is per email verzonden.</p></div>';
            } else {
                $html = 'Er is een fout opgetreden';
            }
        } else {
            $huidige_gebruiker_id = get_current_user_id();
            $html = '<form class="kleistad_form" action="' . get_permalink() . '" method="POST" >
        <input type="hidden" name="kleistad_gebruiker_id" value="' . $huidige_gebruiker_id . '" />
        <label for="kleistad_vanaf_datum" >Vanaf</label>&nbsp;
        <input type="date" name="kleistad_vanaf_datum" id="kleistad_vanaf_datum" /><br /><br />
        <label for="kleistad_tot_datum" >Tot</label>&nbsp;
        <input type="date" name="kleistad_tot_datum" id="kleistad_tot_datum" /><br /><br />
        <button type="submit" name="kleistad_stookbestand_verzonden" id="kleistad_stookbestand_verzonden">Verzenden</button><br />
        </form>';
        }
        return $html;
    }

    /**
     * shortcode handler voor bijwerken saldo formulier [kleistad_saldo]
     * 
     * @return string (html)
     */
    public function saldo_handler() {
        if (!$this->reserveer()) {
            return '';
        }
        wp_enqueue_style('kleistad-css');
        if (!is_null(filter_input(INPUT_POST, 'kleistad_gebruiker_id'))) {
            $gebruiker_id = filter_input(INPUT_POST, 'kleistad_gebruiker_id', FILTER_VALIDATE_INT);
            $saldo = number_format((float) get_user_meta($gebruiker_id, 'stooksaldo', true), 2, ',', '');
        }
        /*
         * Het onderstaande moet voorkomen dat iemand door een pagina refresh opnieuw melding maakt van een saldo storting
         */
        if (!is_null(filter_input(INPUT_POST, 'kleistad_saldo_verzonden')) && wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_saldo' . $gebruiker_id . $saldo)) {
            $via = filter_input(INPUT_POST, 'kleistad_via', FILTER_SANITIZE_SPECIAL_CHARS);
            $bedrag = filter_input(INPUT_POST, 'kleistad_bedrag', FILTER_VALIDATE_INT);
            $datum = strftime('%d-%m-%Y', strtotime(filter_input(INPUT_POST, 'kleistad_datum')));
            $gebruiker = get_userdata($gebruiker_id);

            $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
            $message = "<p>Beste $gebruiker->first_name</p><br />
                <p>Bij deze bevestigen we ontvangst van de melding via <a href=\"www.kleistad.nl\" >www.kleistad.nl</a> dat er per <strong>$datum</strong> een bedrag van <strong>&euro; $bedrag</strong> betaald is per <strong>$via</strong> voor aanvulling van het stooksaldo.</p>";
            if ($this->mail($to, 'wijziging stooksaldo', $message, true)) {
                $huidig = (float) get_user_meta($gebruiker_id, 'stooksaldo', true);
                $saldo = $bedrag + $huidig;
                update_user_meta($gebruiker->ID, 'stooksaldo', $saldo);
                $this->log_saldo("wijziging saldo $gebruiker->display_name van $huidig naar $saldo, betaling per $via.");
                $html = "<div><p>Het saldo is bijgewerkt naar &euro; $saldo en een email is verzonden.</p></div>";
            } else {
                $html = '<div><p>Er is een fout opgetreden want de email kon niet verzonden worden</p></div>';
            }
        } else {
            $datum = date('Y-m-j');
            $huidige_gebruiker_id = get_current_user_id();
            $saldo = number_format((float) get_user_meta($huidige_gebruiker_id, 'stooksaldo', true), 2, ',', '');
            $html = '<p>Je huidige stooksaldo is <strong>&euro; ' . $saldo . '</strong></p>
        <p>Je kunt onderstaand melden dat je het saldo hebt aangevuld</p><hr />
        <form class="kleistad_form" action="' . get_permalink() . '" method="POST">';
            $html .= wp_nonce_field('kleistad_saldo' . $huidige_gebruiker_id . $saldo, '_wpnonce', false, false);
            $html .= '<input type="hidden" name="kleistad_gebruiker_id" value="' . $huidige_gebruiker_id . '" />
        <fieldset><legend>Betaald</legend>
        <label for="kleistad_bank">Bank
        <input type="radio" name="kleistad_via" id="kleistad_bank" value="bank" checked="checked" /></label>
        <label for="kleistad_kas">Contant
        <input type="radio" name="kleistad_via" id="kleistad_kas" value="kas" /></label>
        </fieldset>
        <fieldset><legend>Bedrag</legend>
        <label for="kleistad_b15">&euro; 15
        <input type="radio" name="kleistad_bedrag" id="kleistad_b15" value="15" /></label>
        <label for="kleistad_b30">&euro; 30
        <input type="radio" name="kleistad_bedrag" id="kleistad_b30" value="30" checked="checked" /></label>
        </fieldset>
        <label for="kleistad_datum">Datum betaald</label>
        <input type="date" name="kleistad_datum" id="kleistad_datum" value="' . $datum . '" /><br /><br />
        <label for="kleistad_controle">Klik dit aan voordat je verzendt</label>&nbsp;
        <input type="checkbox" id="kleistad_controle"
            onchange="document.getElementById(\'kleistad_saldo_verzonden\').disabled = !this.checked;" /><br /><br />
        <button type="submit" name="kleistad_saldo_verzonden" id="kleistad_saldo_verzonden" disabled>Verzenden</button><br />
    </form>';
        }
        return $html;
    }

    /**
     * shortcode handler voor reserveringen formulier [kleistad oven=x, naam=y]
     * 
     * @param type $atts
     * @return string (html)
     */
    public function reservering_handler($atts) {
        if (!$this->reserveer()) {
            return '';
        }
        wp_enqueue_script('kleistad-js');
        wp_enqueue_style('kleistad-css');
        add_thickbox();
        $oven = 0;

        extract(shortcode_atts(['oven' => 'niet ingevuld'], $atts, 'kleistad'));
        if (intval($oven) > 0 && intval($oven) < 999) {
            global $wpdb;
            $naam = $wpdb->get_var("SELECT naam FROM {$wpdb->prefix}kleistad_ovens WHERE id = '$oven'");
            if ($naam == null) {
                return "<p>oven met id $oven is niet bekend in de database !</p>";
            }

            $gebruikers = get_users(['fields' => ['id', 'display_name'], 'orderby' => ['nicename']]);
            $huidige_gebruiker = wp_get_current_user();
            $html = "<h1 id=\"kleistad$oven\">Reserveringen voor de $naam</h1>
                <table id=\"reserveringen$oven\" class=\"kleistad_reserveringen\"
                    data-oven=\"$oven\" data-maand=\"" . date('n') . "\" data-jaar=\"" . date('Y') . "\" >
                    <tr><th>de reserveringen worden opgehaald...</th></tr>
                </table>
                <div id =\"kleistad_oven$oven\" class=\"thickbox kleistad_form_popup\">
                    <form id=\"kleistad_form$oven\" action=\"#\" method=\"post\">
                    <table class=\"kleistad_form\">
                    <thead>
                        <tr>
                        <th colspan=\"3\">Reserveer $naam op <span id=\"kleistad_wanneer$oven\"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                            <tr><td></td>";
            if ($this->override()) {
                $html .= "<td colspan=\"2\"><select id=\"kleistad_gebruiker_id$oven\" class=\"kleistad_gebruiker\" data-oven=\"$oven\" >";
                foreach ($gebruikers as $gebruiker) {
                    $selected = ($gebruiker->id == $huidige_gebruiker->ID) ? "selected" : "";
                    $html .= "<option value=\"{$gebruiker->id}\" $selected>{$gebruiker->display_name}</option>";
                }
                $html .= "</select></td>";
            } else {
                $html .= "<td colspan=\"2\"><input type =\"hidden\" id=\"kleistad_gebruiker_id$oven\"></td>";
            }
            $html .= "</tr>
                    <tr>
                        <td><label>Soort stook</label></td>
                        <td colspan=\"2\"><select id=\"kleistad_soortstook$oven\">
                            <option value=\"Biscuit\" selected>Biscuit</option>
                            <option value=\"Glazuur\" >Glazuur</option>
                            <option value=\"Overig\" >Overig</option>
                        </select></td>
                    </tr>
                    <tr>
                        <td><label>Temperatuur</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"100\" max=\"1300\" id=\"kleistad_temperatuur$oven\"></td>
                    </tr>
                    <tr>
                        <td><label>Programma</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"1\" max=\"19\" id=\"kleistad_programma$oven\"></td>
                    </tr>
                    <tr>
                        <td><label>Tijdstip stoken</label></td>
                        <td colspan=\"2\"><select id=\"kleistad_opmerking$oven\">
                                <option value=\"voor 13:00 uur\" >voor 13:00 uur</option>
                                <option value=\"tussen 13:00 en 16:00 uur\" >tussen 13:00 en 16:00 uur</option>
                                <option value=\"na 16:00 uur\" >na 16:00 uur</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label>Stoker</label></td>
                        <td><span id=\"kleistad_stoker$oven\">$huidige_gebruiker->display_name</span> <input type=\"hidden\" id=\"kleistad_1e_stoker$oven\" name=\"kleistad_stoker_id$oven\" value=\"$huidige_gebruiker->ID\"/></td>
                        <td><input type=\"number\" name=\"kleistad_stoker_perc$oven\" readonly />%</td>
                    </tr>";
            for ($i = 2; $i <= 5; $i++) {
                $html .= "<tr>
                        <td><label>Stoker</label></td>
                        <td><select name=\"kleistad_stoker_id$oven\" class=\"kleistad_verdeel\" data-oven=\"$oven\" >
                        <option value=\"0\" >&nbsp;</option>";
                foreach ($gebruikers as $gebruiker) {
                    if (($gebruiker->id <> $huidige_gebruiker->ID) || $this->override()) {
                        $html .= "<option value=\"{$gebruiker->id}\">{$gebruiker->display_name}</option>";
                    }
                }
                $html .= "</select></td>
                    <td><input type=\"number\" data-oven=\"$oven\" class=\"kleistad_verdeel\" name=\"kleistad_stoker_perc{$oven}\" min=\"0\" max=\"100\" >%</td>
                </tr>";
            }
            $html .= "</tbody>
                <tfoot>
                    <tr>
                        <th colspan=\"3\">
                        <input type =\"hidden\" id=\"kleistad_dag$oven\">
                        <input type =\"hidden\" id=\"kleistad_maand$oven\">
                        <input type =\"hidden\" id=\"kleistad_jaar$oven\">
                        <span id=\"kleistad_tekst$oven\"></span></th>
                    </tr>
                    <tr>
                        <th><button type=\"button\" id=\"kleistad_muteer$oven\" class=\"kleistad_muteer\" data-oven=\"$oven\" >Wijzig</button></th>
                        <th><button type=\"button\" id=\"kleistad_verwijder$oven\" class=\"kleistad_verwijder\" data-oven=\"$oven\" >Verwijder</button></th>
                        <th><button type=\"button\" onclick=\"self.parent.tb_remove();return false\" >Sluit</button></th>
                    </tr>
                </tfoot>
            </table>
            </form>
        </div>";
            return $html;
        } else {
            return "<p>de shortcode bevat geen oven nummer tussen 1 en 999 !</p>";
        }
    }

    /**
     * Scheduled job, update elke nacht de saldi
     */
    public function update_ovenkosten() {
        $this->log_saldo("verwerking stookkosten gestart.");
        global $wpdb;
        $vandaag = date('Y-m-d');

        /*
         * saldering transacties uitvoeren
         */
        $transactie_datum = date('Y-m-d', strtotime('- ' . self::TERMIJN . ' days'));
        $transacties = $wpdb->get_results(
            "SELECT RE.id AS id, gebruiker_id, oven_id, naam, verdeling, kosten, dag, maand, jaar FROM
                {$wpdb->prefix}kleistad_reserveringen RE,
                {$wpdb->prefix}kleistad_ovens OV
                WHERE RE.oven_id = OV.id AND verwerkt = '0' AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') <= '$transactie_datum'");
        foreach ($transacties as $transactie) {
            $datum = strftime('%d-%m-%Y', mktime(0, 0, 0, $transactie->maand, $transactie->dag, $transactie->jaar));
            if ($transactie->verdeling == '') {
                $stookdelen = [ ['id' => $transactie->gebruiker_id, 'perc' => 100],
                    ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0],];
            } else {
                $stookdelen = json_decode($transactie->verdeling, true);
            }
            $gebruiker = get_userdata($transactie->gebruiker_id);
            foreach ($stookdelen as $stookdeel) {
                if (intval($stookdeel['id']) == 0) {
                    continue;
                }
                $stoker = get_userdata($stookdeel['id']);

                $regeling = $this->lees_regeling($stookdeel['id'], $transactie->oven_id);
                $kosten = ( $regeling < 0 ) ? $transactie->kosten : $regeling;
                $prijs = round($stookdeel['perc'] / 100 * $kosten, 2);

                $huidig = (float) get_user_meta($stookdeel['id'], 'stooksaldo', true);
                $nieuw = ($huidig == '') ? 0 - (float) $prijs : round((float) $huidig - (float) $prijs, 2);

                $this->log_saldo("wijziging saldo $gebruiker->display_name van $huidig naar $nieuw, stook op $datum.");
                update_user_meta($stookdeel['id'], 'stooksaldo', $nieuw);
                $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['verwerkt' => true], ['id' => $transactie->id], ['%d'], ['%d']);

                $bedrag = number_format($prijs, 2, ',', '');
                $saldo = number_format($nieuw, 2, ',', '');

                $to = "$stoker->first_name $stoker->last_name <$stoker->user_email>";
                $message = "<p>Beste $stoker->first_name,</p><br />
                    <p>je stooksaldo is verminderd met &euro; $bedrag en is nu <strong>&euro; $saldo</strong>.</p>";
                $message .= $gebruiker->ID == $stoker->ID ? "<p>Je hebt " : "<p>$gebruiker->first_name $gebruiker->last_name heeft "; 
                $message .= "aangegeven dat jij {$stookdeel['perc']} % gebruikt hebt van de stook op $datum in de $transactie->naam.</p>
                    <p>Je kunt op de website van Kleistad wanneer je ingelogd bent je persoonlijke <a href=\"http://www.kleistad.nl/leden/gegevens-stook/\">stookoverzicht</a> bekijken.</p>";
                $this->mail($to, 'Kleistad kosten zijn verwerkt op het stooksaldo', $message);
            }
        }
        /*
         * de notificaties uitsturen voor stook die nog niet verwerkt is. 
         */
        $notificaties = $wpdb->get_results(
            "SELECT RE.id AS id, oven_id, naam, kosten, gebruiker_id, dag, maand, jaar FROM
                {$wpdb->prefix}kleistad_reserveringen RE,
                {$wpdb->prefix}kleistad_ovens OV
            WHERE RE.oven_id = OV.id AND gemeld = '0' AND verwerkt = '0' AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') < '$vandaag'");
        foreach ($notificaties as $notificatie) {
            // send reminder email
            $datum = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag, $notificatie->jaar));
            $datum_verwerking = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag + self::TERMIJN, $notificatie->jaar));
            $datum_deadline = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag + self::TERMIJN - 1, $notificatie->jaar));
            $gebruiker = get_userdata($notificatie->gebruiker_id);

            // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
            $regeling = $this->lees_regeling($notificatie->gebruiker_id, $notificatie->oven_id);
            $kosten = number_format(( $regeling < 0 ) ? $notificatie->kosten : $regeling, 2, ',', '');

            $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['gemeld' => 1], ['id' => $notificatie->id], ['%d'], ['%d']);

            $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
            $message = "<p>Beste $gebruiker->first_name,</p><br />
                 <p>je hebt nu de $notificatie->naam in gebruik. Er zal op <strong>$datum_verwerking</strong> maximaal <strong>&euro; $kosten</strong> van je stooksaldo worden afgeschreven.</p>
                 <p>Controleer voor deze datum of je de verdeling van de stookkosten onder de eventuele medestokers hebt doorgegeven in de <a href=\"http://www.kleistad.nl/leden/oven-reserveren/\">reservering</a> van de oven. Je kunt nog wijzigingen aanbrengen tot <strong>$datum_deadline</strong>. Daarna kan er niets meer gewijzigd worden!</p>";
            $this->mail($to, "Kleistad oven gebruik op $datum", $message);
        }
        $this->log_saldo("verwerking stookkosten gereed.");
    }

    /**
     * Callback handler (wordt vanuit browser aangeroepen) voor het tonen van de reserveringen
     * 
     * @global type $wpdb
     * @param WP_REST_Request $request
     * @return \WP_REST_response
     */
    public function callback_show_reservering(WP_REST_Request $request) {
        global $wpdb;
        $oven = intval($request->get_param('oven_id'));
        $maand = intval($request->get_param('maand'));
        $volgende_maand = $maand < 12 ? $maand + 1 : 1;
        $vorige_maand = $maand > 1 ? $maand - 1 : 12;
        $jaar = intval($request->get_param('jaar'));
        $volgende_maand_jaar = $maand < 12 ? $jaar : $jaar + 1;
        $vorige_maand_jaar = $maand > 1 ? $jaar : $jaar - 1;
        $maandnaam = [1 => "januari", 2 => "februari", 3 => "maart", 4 => "april", 5 => "mei", 6 => "juni", 7 => "juli", 8 => "augustus", 9 => "september", 10 => "oktober", 11 => "november", 12 => "december"];
        $html = "
            <thead>
                <tr>
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\" data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th data-align=\"right\"><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\" data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
                </tr>
                <tr>
                    <th>Dag</th>
                    <th>Wie?</th>
                    <th>Soort stook</th>
                    <th data-align=\"right\">Temp</th>
                    <th>Tijdstip stoken</th>
                </tr>
            </thead>
            <tbody>";

        $reserveringen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kleistad_reserveringen WHERE maand='$maand' AND jaar='$jaar' AND oven_id='$oven'");

        $huidige_gebruiker_id = get_current_user_id();

        $aantaldagen = date('t', mktime(0, 0, 0, $maand, 1, $jaar));
        $dagnamen = [1 => 'maandag', 3 => 'woensdag', 5 => 'vrijdag'];

        for ($dagteller = 1; $dagteller <= $aantaldagen; $dagteller++) {
            $datum = mktime(23, 59, 0, $maand, $dagteller, $jaar); // 18:00 uur 's middags
            $weekdag = date('N', $datum);
            switch ($weekdag) {
                case 1:
                case 3:
                case 5:  // alleen maandag, woensdag, vrijdag
                    $kleur = 'lightblue';
                    $soortstook = '';
                    $temperatuur = '';
                    $programma = '';
                    $verdeling = [ ['id' => $huidige_gebruiker_id, 'perc' => 100],
                        ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0],];
                    $opmerking = '';
                    $gereserveerd = false;
                    $verwerkt = false;
                    $datum_verstreken = $datum < time();
                    $wijzigbaar = !$datum_verstreken || is_super_admin();
                    $verwijderbaar = false;
                    $wie = $wijzigbaar ? '-beschikbaar-' : '';
                    $gebruiker_id = $huidige_gebruiker_id;

                    foreach ($reserveringen as $reservering) {
                        if (($reservering->jaar == $jaar) && ( $reservering->maand == $maand) && ( $reservering->dag == $dagteller)) {
                            $gebruiker_info = get_userdata($reservering->gebruiker_id);
                            $wie = $gebruiker_info->display_name;
                            $soortstook = $reservering->soortstook;
                            $temperatuur = $reservering->temperatuur;
                            $programma = $reservering->programma;
                            $verdeling = json_decode($reservering->verdeling, true);
                            $opmerking = $reservering->opmerking;
                            $verwerkt = ($reservering->verwerkt == 1);
                            $gereserveerd = true;
                            if ($reservering->gebruiker_id == $huidige_gebruiker_id) {
                                $kleur = !$datum_verstreken ? 'green' : $kleur;
                                $wijzigbaar = !$verwerkt || is_super_admin();
                                $verwijderbaar = $this->override() ?
                                    !$verwerkt : !$datum_verstreken;
                            } else {
                                $kleur = !$datum_verstreken ? 'red' : $kleur;
                                // als de huidige gebruiker geen bevoegdheid heeft, dan geen actie
                                $wijzigbaar = (!$verwerkt && $this->override()) || is_super_admin();
                                $verwijderbaar = !$verwerkt && $this->override();
                                $gebruiker_id = $reservering->gebruiker_id;
                            }
                            break; // exit de foreach loop
                        }
                    }
                    $html .= "
                <tr  style=\"background-color: $kleur\">"; // deze inlijn style is noodzakelijk omdat de kleur vanuit de backend bepaald wordt
                    if ($wijzigbaar) {
                        $form_data = [
                            'oven' => $oven,
                            'dag' => $dagteller,
                            'maand' => $maand,
                            'jaar' => $jaar,
                            'gebruiker_id' => $gebruiker_id,
                            'wie' => $wie,
                            'soortstook' => ($soortstook == '' ? 'Biscuit' : $soortstook),
                            'temperatuur' => $temperatuur,
                            'programma' => $programma,
                            'verdeling' => $verdeling,
                            'opmerking' => $opmerking,
                            'verwijderbaar' => $verwijderbaar,
                            'gereserveerd' => $gereserveerd,];
                        $html .= "
                    <th><a class=\"thickbox kleistad_box\"  href=\"#TB_inline?width=340&height=500&inlineId=kleistad_oven$oven\" rel=\"bookmark\"
                        data-form='" . json_encode($form_data) . "'
                        id=\"kleistad_$dagteller\">$dagteller $dagnamen[$weekdag] </a></th>";
                    } else {
                        $html .= "
                    <th>$dagteller $dagnamen[$weekdag]</th>";
                    }
                    $html .= "
                    <td>$wie</td>
                    <td>$soortstook</td>
                    <td>$temperatuur</td>
                    <td>$opmerking</td>
                </tr>";
                    break;
                default:
                    break;
            }
        }
        $html .= "
            </tbody>
            <tfoot>
                <tr>
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\" data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th data-align=\"right\"><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\" data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
                </tr>
            </tfoot>";
        return new WP_REST_response(['html' => $html, 'id' => $oven]);
    }

    /**
     * Callback handler (wordt vanuit browser aangeroepen) voor het wijzigen van de reserveringen
     * @global type $wpdb
     * @param WP_REST_Request $request
     * @return type
     */
    public function callback_muteren(WP_REST_Request $request) {
        global $wpdb;
        $gebruiker_id = intval($request->get_param('gebruiker_id'));
        $oven = intval($request->get_param('oven_id'));
        $dag = intval($request->get_param('dag'));
        $maand = intval($request->get_param('maand'));
        $jaar = intval($request->get_param('jaar'));
        $temperatuur = intval($request->get_param('temperatuur'));
        $soortstook = sanitize_text_field($request->get_param('soortstook'));
        $programma = intval($request->get_param('programma'));
        $verdeling = $request->get_param('verdeling');
        $opmerking = sanitize_text_field($request->get_param('opmerking'));

        $reservering = $wpdb->get_row(
            "SELECT gebruiker_id, id FROM {$wpdb->prefix}kleistad_reserveringen
             WHERE maand='$maand' AND jaar='$jaar' AND dag='$dag' AND oven_id='" . absint($oven) . "'");
        if ($oven > 0) {
            // het betreft een toevoeging of wijziging
            // check of er al niet een bestaande reservering is
            if (is_null($reservering)) {
                //reservering toevoegen of wijzigen
                $wpdb->insert("{$wpdb->prefix}kleistad_reserveringen", [
                    'oven_id' => $oven,
                    'dag' => $dag,
                    'maand' => $maand,
                    'jaar' => $jaar,
                    'temperatuur' => $temperatuur,
                    'soortstook' => $soortstook,
                    'programma' => $programma,
                    'verdeling' => $verdeling,
                    'opmerking' => $opmerking,
                    'gebruiker_id' => $gebruiker_id], ['%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d']);
            } elseif (($reservering->gebruiker_id == $gebruiker_id) || $this->override()) {
                $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", [
                    'temperatuur' => $temperatuur,
                    'soortstook' => $soortstook,
                    'programma' => $programma,
                    'verdeling' => $verdeling,
                    'opmerking' => $opmerking,
                    'gebruiker_id' => $gebruiker_id], ['id' => $reservering->id], ['%s', '%s', '%d', '%s', '%s', '%d'], ['%d']);
            } else {
                //er is door een andere gebruiker al een reservering aangemaakt, niet toegestaan
            }
        } else {
            // het betreft een annulering, mag alleen verwijderd worden door de gebruiker of een bevoegde
            if (!is_null($reservering) && ( ( $reservering->gebruiker_id == $gebruiker_id) || $this->override() )) {
                $wpdb->delete("{$wpdb->prefix}kleistad_reserveringen", ['id' => $reservering->id], ['%d']);
            } else {
                //de reservering is al verwijderd of de gebruiker mag dit niet
            }
        }
        $request->set_param('oven_id', absint($oven)); // zorg dat het over_id correct is
        return $this->callback_show_reservering($request);
    }

}
