<?php

/*
  Class: Kleistad
  Description: Basis klas voor kleistad_reserveren plugin
  Version: 1.1
  Author: Eric Sprangers
  Author URI: http://www.sprako.nl/
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

define('KLEISTAD_VERSION', 2);
define('KLEISTAD_URL', 'kleistad_reserveren/v1');
define('KLEISTAD_EMAIL', 'info@casusopmaat.nl'); // NOG AANPASSEN !!!
define('KLEISTAD_OVERRIDE', 'override_reservering');

class Kleistad {
    
    /**
     * constructor, alleen registratie van acties
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('wp_enqueue_scripts', [$this, 'register_scripts']);
        add_action('kleistad_kosten', [$this, 'update_ovenkosten']);
        add_shortcode('kleistad_rapport', [$this, 'rapport_handler']);
        add_shortcode('kleistad_saldo', [$this, 'saldo_handler']);
        add_shortcode('kleistad_saldo_overzicht', [$this, 'saldo_overzicht_handler']);
        add_shortcode('kleistad_stookbestand', [$this, 'stookbestand_handler']);
        add_shortcode('kleistad', [$this, 'reservering_handler']);
        add_shortcode('kleistad_ovens', [$this, 'ovens_handler']);
        add_shortcode('kleistad_regeling', [$this, 'regeling_handler']);
        add_filter('widget_text', 'do_shortcode');        
    }
    /**
     * 
     * @global type $wpdbdatabase tabellen aanmaken of aanpassen, alleen bij activering plugin
     */
    private static function database() {
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
    }
    /**
     * activeer plugin
     */
    static function activate() {
        self::database();

        if (!wp_next_scheduled('kleistad_kosten')) {
            wp_schedule_event(strtotime("midnight"), 'daily', 'kleistad_kosten');
        }

        global $wp_roles;
        $wp_roles->add_cap('administrator', 'KLEISTAD_OVERRIDE');
        $wp_roles->add_cap('editor', 'KLEISTAD_OVERRIDE');
        $wp_roles->add_cap('author', 'KLEISTAD_OVERRIDE');
    }
    /**
     * deactiveer plugin
     */
    static function deactivate() {
        wp_clear_scheduled_hook('kleistad_kosten');

        global $wp_roles;
        $wp_roles->remove_cap('administrator', 'KLEISTAD_OVERRIDE');
        $wp_roles->remove_cap('editor', 'KLEISTAD_OVERRIDE');
        $wp_roles->remove_cap('author', 'KLEISTAD_OVERRIDE');
    }
    /**
     * registreer de AJAX endpoints
     */
    public function register_endpoints() {
        register_rest_route(
            KLEISTAD_URL, '/reserveer', [
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
            KLEISTAD_URL, '/show', [
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
            'kleistad-js', plugins_url('../js/kleistad.js', __FILE__), ['jquery'], KLEISTAD_VERSION, true
        );
        wp_register_style(
            'kleistad-css', plugins_url('../css/kleistad.css', __FILE__), [], KLEISTAD_VERSION
        );
        
        wp_localize_script(
            'kleistad-js', 'kleistad_data', [
                'nonce' => wp_create_nonce('wp_rest'),
                'base_url' => rest_url(KLEISTAD_URL),
                'success_message' => 'de reservering is geslaagd!',
                'error_message' => 'het was niet mogelijk om de reservering uit te voeren'
            ]
        );
    }
    /**
     * help functie, bestuursleden kunnen publiceren en mogen daarom aanpassen
     * @return bool
     */
    private function override() {
        return current_user_can('KLEISTAD_OVERRIDE');
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
            if (array_key_exists ($oven_id, $ovenkosten)) {
                return $ovenkosten[ $oven_id ];
            }
        }
        return false;
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
        $ovenkosten [ $oven_id ] = $tarief;
        update_user_meta($gebruiker_id, 'ovenkosten', json_encode($ovenkosten));
    }
    /**
     * shortcode handler voor tonen van saldo van gebruikers [kleistad_saldo_overzicht]
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function saldo_overzicht_handler($atts, $contents = null) {
        if (!is_user_logged_in() || !$this->override()) {
            return '';
        }
        $gebruikers = get_users(['fields' => ['id', 'display_name'], 'orderby' => ['nicename']]);
        $html = "<table class=\"kleistad_rapport\">
            <thead>
                <tr><th>Naam</th><th>Saldo</th></tr>
            </thead>
            <tbody>";
        foreach ($gebruikers as $gebruiker) {
            $saldo = number_format((float)get_user_meta($gebruiker->id, 'stooksaldo', true), 2, ',', '');
            $html .= "<tr><td>$gebruiker->display_name</td><td>$saldo</td></tr>";    
        }
        $html .="</tbody>
            </table>";
        return $html;
    }
    /**
     * shortcode handler voor tonen van rapporten [kleistad_rapport]
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function rapport_handler($atts, $contents = null) {
        if (!is_user_logged_in()) {
            return '';
        }
        wp_enqueue_style('kleistad-css');

        $huidige_gebruiker_id = get_current_user_id();
        $datum_begin = date('Y-m-d', strtotime('- 6 months')); // laatste half jaar
        
        global $wpdb;
        $reserveringen = $wpdb->get_results(
            "SELECT RE.id AS id, oven_id, naam, kosten, soortstook, temperatuur, programma,gebruiker_id, dag, maand, jaar, verdeling, verwerkt FROM
                {$wpdb->prefix}kleistad_reserveringen RE,
                {$wpdb->prefix}kleistad_ovens OV
            WHERE RE.oven_id = OV.id AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') > $datum_begin 
                    ORDER BY jaar, maand, dag ASC");
        $html = "<table class=\"kleistad_rapport\">
            <thead>
                <tr><th>Datum</th><th>Oven</th><th>Stoker</th><th>Stook</th><th>Temp</th><th>Prog#</th><th>%</th><th>Kosten</th><th>Voorlopig</th></tr>
            </thead>
            <tbody>";
        foreach ($reserveringen as $reservering) {
            if ($reservering->verdeling == null) {
                continue;
            }
            $stookdelen = json_decode($reservering->verdeling, true);
            foreach ($stookdelen as $stookdeel) {
                if (intval($stookdeel['id']) <> $huidige_gebruiker_id) {
                    continue;
                }
                // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
                $regeling = $this->lees_regeling($reservering->gebruiker_id, $reservering->oven_id);
                $kosten = number_format(round( $stookdeel['perc']/100 * ( ( !$regeling ) ? $reservering->kosten : $regeling ), 2), 2,',','');
                $stoker = get_userdata($reservering->gebruiker_id);
                $gereserveerd = $reservering->verwerkt != 1 ? 'x' : '';
                $html .= "
                    <tr>
                        <td>$reservering->dag/$reservering->maand</td>
                        <td>$reservering->naam</td>
                        <td>$stoker->display_name</td>
                        <td>$reservering->soortstook</td>
                        <td>$reservering->temperatuur</td>
                        <td>$reservering->programma</td>
                        <td>{$stookdeel['perc']}</td>
                        <td>€ $kosten</td>
                        <td>$gereserveerd</td>
                    </tr>";
            }
        }
        $html .="</tbody>
            </table>";
        return $html;
    }
    /**
     * shortcode handler voor het beheer van de ovens
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function ovens_handler($atts, $contents = null) {
        if (!is_user_logged_in() || !$this->override()) {
            return '';
        }
        global $wpdb;

        if (isset($_POST['kleistad_ovens_verzonden'])) {
            $naam = sanitize_text_field($_POST['kleistad_oven_naam']);
            $tarief = str_replace(",",".",$_POST['kleistad_oven_tarief']);
            $wpdb->insert("{$wpdb->prefix}kleistad_ovens", ['naam' => $naam, 'kosten' => $tarief], ['%s', '%s']);
        }
            
        $ovens = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}kleistad_ovens ORDER BY id");
        $html = "<table class=\"kleistad_rapport\">
            <thead>
                <tr><th>Id</th><th>Naam</th><th>Tarief</th></tr>
            </thead>
            <tbody>";
        foreach ($ovens as $oven) {
            $html .= "<tr><td>$oven->id</td><td>$oven->naam</td><td>$oven->kosten</td></tr>";
        }
        $html .= "</tbody></table>
        <p>Nieuwe oven aanmaken</p> 
        <form action=\"" . esc_url($_SERVER['REQUEST_URI']) . "\" method=\"POST\">
            <label for=\"kleistad_oven_naam\">Naam</label>&nbsp;
            <input type=\"text\" maxlength=\"\" name=\"kleistad_oven_naam\" id=\"kleistad_oven_naam\" /><br />
            <label for=\"kleistad_oven_tarief\">Tarief</label>&nbsp;
            <input type=\"number\" step=\"any\" name=\"kleistad_oven_tarief\" id=\"kleistad_oven_tarief\" /><br /><br />
            <button type=\"submit\" name=\"kleistad_ovens_verzonden\" id=\"kleistad_ovens_verzonden\">Verzenden</button><br />
        </form>";
        
        return $html;
    }
    /**
     * shortcode handler voor het beheer van speciale regelingen
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function regeling_handler($atts, $contents = null) {
        if (!is_user_logged_in() || !$this->override()) {
            return '';
        }
        global $wpdb;

        if (isset($_POST['kleistad_regeling_verzonden'])) {
            $gebruiker_id = intval($_POST['kleistad_regeling_gebruiker_id']);
            $oven_id = intval($_POST['kleistad_regeling_id']);
            $tarief = str_replace(",",".",$_POST['kleistad_regeling_tarief']);
            
            $this->maak_regeling ($gebruiker_id, $oven_id, $tarief);
        }
        $gebruikers = get_users(['fields' => ['id', 'display_name'], 'orderby' => ['nicename']]);
        
        $html = "<table class=\"kleistad_rapport\">
            <thead>
                <tr><th>Naam</th><th>Oven id</th><th>Tarief</th></tr>
            </thead>
            <tbody>";
        foreach ($gebruikers as $gebruiker) {
            $regelingen = $this->lees_regeling($gebruiker->id);
            if ($regelingen == false) {
                continue;
            }
            foreach ( $regelingen as $id => $regeling) {
                $html .= "<tr><td>$gebruiker->display_name</td><td>$id</td><td>$regeling</td></tr>";
            }
        }
        $html .= "</tbody></table>

        <p>Nieuwe regeling aanmaken of bestaande wijzigen</p> 
        <form action=\"" . esc_url($_SERVER['REQUEST_URI']) . "\" method=\"POST\">
            <label for=\"kleistad_regeling_id\">Oven id</label>&nbsp;
            <input type=\"number\" name=\"kleistad_regeling_id\" id=\"kleistad_regeling_id\" /><br />
            <label for=\"kleistad_regeling_gebruiker_id\">Gebruiker</label>&nbsp;
            <select name=\"kleistad_regeling_gebruiker_id\" id=\"kleistad_regeling_gebruiker_id\" />";
        foreach ($gebruikers as $gebruiker) {
            $html .= "<option value=\"$gebruiker->id\" >$gebruiker->display_name</option>";
        }
        $html .= "</select><br />
            <label for=\"kleistad_regeling_tarief\">Tarief</label>&nbsp;
            <input type=\"number\" step=\"any\" name=\"kleistad_regeling_tarief\" id=\"kleistad_regeling_tarief\" /><br /><br />
            <button type=\"submit\" name=\"kleistad_regeling_verzonden\" id=\"kleistad_regeling_verzonden\">Verzenden</button><br />
        </form>";
        
        return $html;
    }
    /**
     * shortcode handler voor emailen van het CSV bestand met transacties [kleistad_stookbestand]
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function stookbestand_handler($atts, $contents = null) {
        if (!is_user_logged_in() || !$this->override()) {
            return '';
        }
        if (isset($_POST['kleistad_stookbestand_verzonden'])) {
            $vanaf_datum = date('Y-m-d', strtotime($_POST["kleistad_vanaf_datum"]));
            $tot_datum = date('Y-m-d', strtotime($_POST["kleistad_tot_datum"]));
            $gebruiker = get_userdata(intval($_POST["kleistad_gebruiker_id"]));

            $bijlage = WP_CONTENT_DIR . "/uploads/stookbestand_" . date('Y_m_d') . ".csv";
            $f = fopen( $bijlage, "w" );
            
            global $wpdb;
            $stoken = $wpdb->get_results(
                "SELECT RE.id AS id, oven_id, naam, kosten, soortstook, temperatuur, programma,gebruiker_id, dag, maand, jaar, verdeling, verwerkt FROM
                    {$wpdb->prefix}kleistad_reserveringen RE,
                    {$wpdb->prefix}kleistad_ovens OV
                WHERE RE.oven_id = OV.id AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') BETWEEN '$vanaf_datum' AND '$tot_datum'
                        ORDER BY jaar, maand, dag ASC");
            
            $medestokers = [];
            foreach ($stoken as $stook) {
                $stookdelen = json_decode($stook->verdeling, true);
                for ($i = 0; $i < 5; $i++) {
                    $medestoker_id = $stookdelen[$i]['id'];
                    if ($medestoker_id > 0 ) {
                        if (!array_key_exists($medestoker_id, $medestokers)) {
                            $medestoker = get_userdata ($medestoker_id);
                            $medestokers[$medestoker_id] = $medestoker->display_name;
                        }
                    }
                }
            }
            asort($medestokers);
            $line = "\"Stoker\";\"Datum\";\"Oven\";\"Kosten\";\"Soort Stook\";\"Temperatuur\";\"Programma\";";
            for ($i = 1; $i <= 2; $i++) {
                foreach ($medestokers as $medestoker) {
                    $line .= "\"$medestoker\";";
                }
            }
            $line .= "\n";
            fwrite($f, $line);
            
            foreach ($stoken as $stook) {
                $stoker = get_userdata($stook->gebruiker_id);
                $stookdelen = json_decode($stook->verdeling, true);
                $kosten = number_format($stook->kosten, 2, ',', '');
                $line = "\"$stoker->display_name\";\"$stook->dag-$stook->maand-$stook->jaar\";\"$stook->naam\";\"$kosten\";\"$stook->soortstook\";\"$stook->temperatuur\";\"$stook->programma\";"; 
                foreach ($medestokers as $id => $medestoker) {
                    $percentage = 0;
                    for ($i = 0; $i < 5; $i ++) {
                        if ($stookdelen[$i]['id'] == $id ) {
                            $percentage = $percentage + $stookdelen[$i]['perc'];
                        }
                    }
                    $line .= ($percentage == 0) ? "\"\";" : "\"$percentage\";";
                }
                foreach ($medestokers as $id => $medestoker) {
                    $percentage = 0;
                    for ($i = 0; $i < 5; $i ++) {
                        if ($stookdelen[$i]['id'] == $id ) {
                            $percentage = $percentage + $stookdelen[$i]['perc'];
                        }
                    }
                    if ($percentage > 0) {
                        // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
                        $regeling = $this->lees_regeling($id, $stook->oven_id);
                        $kosten = number_format(round( ($percentage * ( ( !$regeling ) ? $stook->kosten : $regeling )) /100, 2), 2,',','');
                    } 
                    $line .= ($percentage == 0) ? "\"\";" : "\"$kosten\";";
                }
                $line .= "\n";
                fwrite($f, $line);
            }
            
            fclose ($f);
            
            $headers = [
                "From: Kleistad <" . KLEISTAD_EMAIL .">",
                "To: $gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>",
                "Content-Type: text/html; charset=UTF-8"];
            $message = 
                "<style>p { font-family:calibri; font-size:13pt }</style>
                <p>Bijgaand het bestand in .CSV formaat met alle transacties tussen $vanaf_datum en $tot_datum.</p><br />
                <p>met vriendelijke groet,</p>
                <p>Kleistad</p>";
            $attachments = [ $bijlage ];
            
            if (wp_mail(KLEISTAD_EMAIL, 'wijziging stooksaldo', $message, $headers, $attachments)) {
                $html = "<div><p>Het bestand is per email verzonden.</p></div>";
            } else {
                $html = 'Er is een fout opgetreden';
            }
        } else {
            $datum = date('Y-m-j');
            $huidige_gebruiker_id = get_current_user_id();
            $html = 
        "<form action=\"" . esc_url($_SERVER['REQUEST_URI']) . "\" method=\"POST\">
        <input type=\"hidden\" name=\"kleistad_gebruiker_id\" value=\"$huidige_gebruiker_id\" />
        <label for=\"kleistad_vanaf_datum\">Vanaf</label>&nbsp;
        <input type=\"date\" name=\"kleistad_vanaf_datum\" id=\"kleistad_vanaf_datum\" /><br /><br />
        <label for=\"kleistad_tot_datum\">Vanaf</label>&nbsp;
        <input type=\"date\" name=\"kleistad_tot_datum\" id=\"kleistad_tot_datum\" /><br /><br />
        <button type=\"submit\" name=\"kleistad_stookbestand_verzonden\" id=\"kleistad_stookbestand_verzonden\">Verzenden</button><br />
    </form>";
        }
        return $html;
    }
    /**
     * shortcode handler voor bijwerken saldo formulier [kleistad_saldo]
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function saldo_handler($atts, $contents = null) {
        if (!is_user_logged_in()) {
            return '';
        }
        if (isset($_POST["kleistad_gebruiker_id"])) {
            $gebruiker_id = intval($_POST["kleistad_gebruiker_id"]);
            $saldo = number_format((float)get_user_meta($gebruiker_id, 'stooksaldo', true), 2, ',', '');
        }
        /*
         * Het onderstaande moet voorkomen dat iemand door een pagina refresh opnieuw melding maakt van een saldo storting
         */
        if (isset($_POST['kleistad_saldo_verzonden']) && wp_verify_nonce( $_POST['_wpnonce'], 'kleistad_saldo'.$gebruiker_id.$saldo ) ) {
            $via = sanitize_text_field($_POST["kleistad_via"]);
            $bedrag = intval($_POST["kleistad_bedrag"]);
            $datum = strftime('%x', strtotime($_POST["kleistad_datum"]));
            $gebruiker = get_userdata($gebruiker_id);

            $headers = [
                "From: $gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>",
                "To: Kleistad <" . KLEISTAD_EMAIL .">",
                "Cc: $gebruiker->user_email",
                "Content-Type: text/html; charset=UTF-8"];

            $message = 
                "<style>p { font-family:calibri; font-size:13pt }</style>
                <p>Ik meld dat ik per <strong>$datum</strong> een bedrag van <strong>€ $bedrag</strong> betaald heb per <strong>$via</strong>.</p><br />
                <p>met vriendelijke groet,</p>
                <p>$gebruiker->first_name $gebruiker->last_name</p>";

            if (wp_mail(KLEISTAD_EMAIL, 'wijziging stooksaldo', $message, $headers)) {
                $saldo = $bedrag + (float) get_user_meta($gebruiker_id, 'stooksaldo', true);
                update_user_meta($gebruiker->ID, 'stooksaldo', $saldo);
                $html = "<div><p>Het saldo is bijgewerkt naar € $saldo en een email is verzonden.</p></div>";
            } else {
                $html = 'Er is een fout opgetreden';
            }
        } else {
            $datum = date('Y-m-j');
            $huidige_gebruiker_id = get_current_user_id();
            $saldo = number_format((float)get_user_meta($huidige_gebruiker_id, 'stooksaldo', true), 2, ',', '');
            $html = 
        "<p>Je huidige stooksaldo is <strong>€ $saldo</strong></p>
        <p>Je kunt onderstaand melden dat je het saldo hebt aangevuld</p><hr />
        <form action=\"" . esc_url($_SERVER['REQUEST_URI']) . "\" method=\"POST\">";
            $html .= wp_nonce_field('kleistad_saldo'.$huidige_gebruiker_id.$saldo, '_wpnonce', false, false );
            $html .=
        "<input type=\"hidden\" name=\"kleistad_gebruiker_id\" value=\"$huidige_gebruiker_id\" />
        <label for=\"kleistad_bank\">Per bank overgemaakt</label>&nbsp;
        <input type=\"radio\" name=\"kleistad_via\" id=\"kleistad_bank\" value=\"bank\" checked=\"checked\" /><br />
        <label for=\"kleistad_kas\">Kas betaling op Kleistad</label>&nbsp;
        <input type=\"radio\" name=\"kleistad_via\" id=\"kleistad_kas\" value=\"kas\" /><br /><br />
        <label for=\"kleistad_b15\">15 euro</label>&nbsp;
        <input type=\"radio\" name=\"kleistad_bedrag\" id=\"kleistad_b15\" value=\"15\" /><br />
        <label for=\"kleistad_b30\">30 euro</label>&nbsp;
        <input type=\"radio\" name=\"kleistad_bedrag\" id=\"kleistad_b30\" value=\"30\" checked=\"checked\" /><br /><br />
        <label for=\"kleistad_datum\">Datum betaald</label>
        <input type=\"date\" name=\"kleistad_datum\" id=\"kleistad_datum\" value=\"$datum\" /><br /><br />
        <label for=\"kleistad_controle\">Klik dit aan voordat je verzendt</label>&nbsp;
        <input type=\"checkbox\" id=\"kleistad_controle\"
            onchange=\"document.getElementById('kleistad_saldo_verzonden').disabled = !this.checked;\" /><br />
        <button type=\"submit\" name=\"kleistad_saldo_verzonden\" id=\"kleistad_saldo_verzonden\" disabled>Verzenden</button><br />
    </form>";
        }
        return $html;
    }
    /**
     * shortcode handler voor reserveringen formulier [kleistad oven=x, naam=y]
     * 
     * @param type $atts
     * @param type $contents
     * @return string (html)
     */
    public function reservering_handler($atts, $contents = null) {
        if (!is_user_logged_in()) {
            return '';
        }
        wp_enqueue_script('kleistad-js');
        wp_enqueue_style('kleistad-css');
        add_thickbox();

        extract(shortcode_atts(['oven' => 'niet ingevuld'], $atts, 'kleistad'));
        if (intval($oven) > 0 and intval($oven) < 999) {
            global $wpdb;
            $naam = $wpdb->get_var ("SELECT naam FROM {$wpdb->prefix}kleistad_ovens WHERE id = $oven");
            if ($naam == null) {
                return "<p>oven met id $oven is niet bekend in de database !</p>";
            }
            
            $gebruikers = get_users(['fields' => ['id', 'display_name'], 'orderby' => ['nicename']]);
            $huidige_gebruiker = wp_get_current_user();
            $html = "
    <section>
        <h1 id=\"kleistad$oven\">Reserveringen voor $naam</h1>
        <table id=\"reserveringen$oven\" class=\"kleistad_reserveringen\"
            data-oven=\"$oven\", data-maand=\"" . date("n") . "\" data-jaar=\"" . date("Y") . "\" >
            <tr><th>de reserveringen worden opgehaald...</th></tr>
        </table>
        <div id =\"kleistad_oven$oven\" class=\"thickbox kleistad_form_popup\">
            <form action=\"#\" method=\"post\">
            <table class=\"kleistad_form\">
                <thead>
                    <tr>
                        <th colspan=\"3\">Reserveer $naam op <span id=\"kleistad_wanneer$oven\"></span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td></td>";
            if ($this->override()) {
                $html .= "
                        <td colspan=\"2\">
                            <select id=\"kleistad_gebruiker_id$oven\" class=\"kleistad_gebruiker\" data-oven=\"$oven\" >";
                foreach ($gebruikers as $gebruiker) {
                    $selected = ($gebruiker->id == $huidige_gebruiker->ID) ? "selected" : "";
                    $html .= "
                                <option value=\"{$gebruiker->id}\" $selected>{$gebruiker->display_name}</option>";
                }
                $html .= "
                            </select>
                        </td>";
            } else {
                $html .= "
                        <td colspan=\"2\"><input type =\"hidden\" id=\"kleistad_gebruiker_id$oven\"></td>";
            }
            $html .= "
                    </tr>
                    <tr>
                        <td><label>Soort stook</label></td>
                        <td colspan=\"2\"><select id=\"kleistad_soortstook$oven\">
                                <option value=\"Biscuit\" selected>Biscuit</option><option value=\"Gladbrand\" >Gladbrand</option><option value=\"Overig\" >Overig</option>
                            </select></td>
                    </tr>
                    <tr>
                        <td><label>Temperatuur</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"100\" max=\"1300\" id=\"kleistad_temperatuur$oven\"></td>
                    </tr>
                    <tr>
                        <td><label>Programma</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"0\" max=\"99\" id=\"kleistad_programma$oven\"></td>
                    </tr>
                    <tr>
                        <td><label>Opmerking</label></td>
                        <td colspan=\"2\"><input type=\"text\" maxlength=\"25\" id=\"kleistad_opmerking$oven\"></td>
                    </tr>
                    <tr>
                        <td><label>Stoker</label></td>
                        <td><span id=\"kleistad_stoker$oven\">$huidige_gebruiker->display_name</span> <input type=\"hidden\" id=\"kleistad_1e_stoker$oven\" name=\"kleistad_stoker_id$oven\" value=\"$huidige_gebruiker->ID\"/></td>
                        <td><input type=\"number\" name=\"kleistad_stoker_perc$oven\" readonly />%</td>
                    </tr>";
            for ($i = 2; $i <= 5; $i++) {
                $html .= "
                    <tr>
                        <td><label>Stoker</label></td>
                        <td><select name=\"kleistad_stoker_id$oven\" class=\"kleistad_verdeel\" data-oven=\"$oven\" >
                        <option value=\"0\" ></option>";
                foreach ($gebruikers as $gebruiker) {
                    if ($gebruiker->id <> $huidige_gebruiker->ID) {
                        $html .= "
                            <option value=\"{$gebruiker->id}\">{$gebruiker->display_name}</option>";
                    }
                }
                $html .= "
                        </select></td>
                    <td><input type=\"number\" data-oven=\"$oven\" class=\"kleistad_verdeel\" name=\"kleistad_stoker_perc{$oven}\" min=\"0\" max=\"100\" >%</td>
                </tr>";
            }
            $html .= "
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan=\"3\">
                        <input type =\"hidden\" id=\"kleistad_dag$oven\">
                        <input type =\"hidden\" id=\"kleistad_maand$oven\">
                        <input type =\"hidden\" id=\"kleistad_jaar$oven\">
                        <span id=\"kleistad_tekst$oven\"></span></th>
                    </tr>
                    <tr>
                        <th><button type=\"button\" id=\"kleistad_muteer$oven\" class=\"kleistad_muteer\" data-oven=\"$oven\"></button></th>
                        <th><button type=\"button\" id=\"kleistad_verwijder$oven\" class=\"kleistad_verwijder\" data-oven=\"$oven\">Verwijderen</button></th>
                        <th><button type=\"button\" onclick=\"self.parent.tb_remove();return false\">Annuleren</button></th>
                    </tr>
                </tfoot>
            </table>
            </form>
        </div>
    </section>";
            return $html;
        } else {
            return "<p>de shortcode bevat geen oven nummer tussen 1 en 999 !</p>";
        }
    }
    /**
     * Scheduled job, update elke nacht de saldi
     */
    public function update_ovenkosten() {
        /*
         * allereerst de notificaties uitsturen. 
         */
        global $wpdb;
        $notificaties = $wpdb->get_results(
            "SELECT RE.id AS id, oven_id, naam, kosten, gebruiker_id, dag, maand, jaar FROM
                {$wpdb->prefix}kleistad_reserveringen RE,
                {$wpdb->prefix}kleistad_ovens OV
            WHERE RE.oven_id = OV.id AND gemeld = 0 AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') < now()");
        foreach ($notificaties as $notificatie) {
            // send reminder email
            $datum = strftime('%x', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag, $notificatie->jaar));
            $datum_verwerking = strftime('%x', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag + 4, $notificatie->jaar));
            $gebruiker = get_userdata($notificatie->gebruiker_id);

            // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
            $regeling = $this->lees_regeling($notificatie->gebruiker_id, $notificatie->oven_id);
            $kosten = number_format( (!$regeling) ? $notificatie->kosten : $regeling, 2, ',', '');

            $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['gemeld' => true], ['id' => $notificatie->id], ['%d'], ['%d']);

            $headers = [
                "From: Kleistad <" . KLEISTAD_EMAIL . ">",
                "To: $gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>",
                "Content-Type: text/html; charset=UTF-8"];
            $message = 
                "<style>p { font-family:calibri; font-size:13pt }</style>
                 <p>Beste $gebruiker->first_name,</p><br />
                 <p>je hebt nu $notificatie->naam in gebruik. Er zal op <strong>$datum_verwerking</strong> maximaal <strong>€ $kosten</strong>
                 van je stooksaldo worden afgeschreven.</p>
                 <p>Controleer voorafgaand deze datum of je de verdeling van de stookkosten onder de eventuele medestokers juist hebt
                 ingevuld. Daarna kan dit namelijk niet meer gewijzigd worden!</p><br />
                 <p>met vriendelijke groet,</p>
                 <p>Kleistad</p>";
            wp_mail($gebruiker->user_email, "Kleistad Oven gebruik op $datum", $message, $headers);
        }
        /*
         * saldering transacties uitvoeren
         */
        $transactie_datum = date('Y-m-d', strtotime('+ 4 days'));
        $transacties = $wpdb->get_results(
                "SELECT RE.id AS id, gebruiker_id, oven_id, naam, verdeling, kosten, dag, maand, jaar FROM
                {$wpdb->prefix}kleistad_reserveringen RE,
                {$wpdb->prefix}kleistad_ovens OV
                WHERE RE.oven_id = OV.id AND verwerkt = 0 AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') < '$transactie_datum'");
        foreach ($transacties as $transactie) {
            $datum = strftime('%A, %e %B', mktime(0, 0, 0, $transactie->maand, $transactie->dag, $transactie->jaar));
            if ($transactie->verdeling == '') {
                $stookdelen = [ ['id'=>$transactie->gebruiker_id, 'perc' => 100], 
                    ['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],];
            } else {
                $stookdelen = json_decode($transactie->verdeling, true);
            }
            $gebruiker = get_userdata($transactie->gebruiker_id);
            foreach ($stookdelen as $stookdeel) {
                if (intval($stookdeel['id']) == 0) {
                    continue;
                }
                $stoker = get_userdata($stookdeel['id']);

                $regeling = $this->lees_regeling ($stookdeel['id'], $transactie->oven_id);
                $kosten = ( !$regeling ) ? $transactie->kosten : $regeling;
                $prijs = round($stookdeel['perc'] / 100 * $kosten, 2);

                $huidig = (float) get_user_meta($stookdeel['id'], 'stooksaldo', true);
                $nieuw = ($huidig == '') ? 0 - (float) $prijs : round((float) $huidig - (float) $prijs, 2);
                update_user_meta($stookdeel['id'], 'stooksaldo', $nieuw);
                $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['verwerkt' => true], ['id' => $transactie->id], ['%d'], ['%d']);
                
                $bedrag= number_format ($prijs, 2, ',', '');
                $saldo = number_format ($nieuw, 2, ',', '');
                $headers = [
                     "From: Kleistad <" . KLEISTAD_EMAIL . ">",
                     "To: $stoker->first_name $stoker->last_name <$stoker->user_email>",
                     "Content-Type: text/html; charset=UTF-8"];
                $message = "<p>Beste $stoker->first_name,</p><br />
                    <style>p { font-family:calibri; font-size:13pt }</style>
                    <p>je stooksaldo is verminderd met $bedrag en is nu <strong>$saldo</strong>.</p>
                    <p>$gebruiker->first_name $gebruiker->last_name heeft aangegeven dat jij {$stookdeel['perc']} %
                    gebruikt hebt van de stook op $datum in $transactie->naam.</p><br />
                    <p>met vriendelijke groet,</p>
                    <p>Kleistad</p>";

                wp_mail($stoker->user_email, "Kleistad kosten zijn verwerkt op het stooksaldo", $message, $headers);
            }
        }
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
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\", data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th data-align=\"right\"><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\", data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
                </tr>
                <tr>
                    <th>Dag</th>
                    <th>Wie?</th>
                    <th>Soort stook</th>
                    <th data-align=\"right\">Temp</th>
                    <th>Opmerking</th>
                </tr>
            </thead>
            <tbody>";

        $reserveringen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kleistad_reserveringen WHERE maand='$maand' and jaar='$jaar' and oven_id='$oven'");

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
                    $verdeling = [ ['id'=>$huidige_gebruiker_id, 'perc' => 100], 
                        ['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],['id'=>0,'perc'=>0],];
                    $opmerking = '';
                    $bestaand = '0';
                    $verwerkt = '0';
                    $actie = $datum >= time(); // te reserveren of nog te verwijderen
                    $wie = $actie ? '-beschikbaar-' : '';
                    $gebruiker_id = $huidige_gebruiker_id;

                    foreach ($reserveringen as $reservering) {
                        if (($reservering->jaar == $jaar) and ( $reservering->maand == $maand) and ( $reservering->dag == $dagteller)
                        ) {
                            $gebruiker_info = get_userdata($reservering->gebruiker_id);
                            $wie = $gebruiker_info->display_name;
                            $soortstook = $reservering->soortstook;
                            $temperatuur = $reservering->temperatuur;
                            $programma = $reservering->programma;
                            $verdeling = json_decode($reservering->verdeling, true);
                            $opmerking = $reservering->opmerking;
                            $verwerkt = $reservering->verwerkt;
                            $bestaand = '1';

                            if ($reservering->gebruiker_id == $huidige_gebruiker_id) {
                                $kleur = $actie ? 'green' : $kleur;
                            } else {
                                $kleur = $actie ? 'red' : $kleur;
                                // als de huidige gebruiker geen bevoegdheid heeft, dan geen actie
                                $actie = ($actie and $this->override());
                                $gebruiker_id = $reservering->gebruiker_id;
                            }
                            break; // exit de foreach loop
                        }
                    }
                    $html .= "
                <tr  style=\"background-color: $kleur\">"; // deze inlijn style is noodzakelijk omdat de kleur vanuit de backend bepaald wordt
                    if ($actie || ($bestaand && !$verwerkt)) {
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
                            'actie' => $actie,
                            'bestaand' => $bestaand,];
                        $html .= "
                    <th><a class=\"thickbox kleistad_box\"  href=\"#TB_inline?width=380&height=500&inlineId=kleistad_oven$oven\" rel=\"bookmark\"
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
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\", data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th data-align=\"right\"><button type=\"button\" class=\"kleistad_periode\" data-oven=\"$oven\", data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
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
             WHERE maand='$maand' and jaar='$jaar' and dag='$dag' and oven_id='" . absint($oven) . "'");
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
            } elseif (($reservering->gebruiker_id == $gebruiker_id) or $this->override()) {
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
            if (!is_null($reservering) and ( ( $reservering->gebruiker_id == $gebruiker_id) or $this->override() )) {
                $wpdb->delete("{$wpdb->prefix}kleistad_reserveringen", ['id' => $reservering->id], ['%d']);
            } else {
                //de reservering is al verwijderd of de gebruiker mag dit niet
            }
        }
        $request->set_param('oven_id', absint($oven)); // zorg dat het over_id correct is
        return $this->callback_show_reservering($request);
    }
}
