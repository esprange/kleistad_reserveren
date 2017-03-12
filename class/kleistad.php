<?php
/*
  Class: Kleistad
  Description: Basis klas voor kleistad_reserveren plugin
  Version: 3.0
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
 * 3.0  11-12-2016  Toevoegen functionaliteit voor cursus administratie
 */
defined('ABSPATH') or die("No script kiddies please!");

class Kleistad {

  /**
   * @var Kleistad instance
   */
  protected static $instance = NULL;

  /**
   * @var reserveren_form vlag
   */
  protected static $reserveren_form = false;

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
  const VERSIE = 3;

  /**
   * Aantal dagen tussen reserveer datum en saldo verwerkings datum
   */
  const TERMIJN = 4;

  /**
   * User meta variabele welke alle cursus inschrijvingen bevat
   */
  const INSCHRIJVINGEN = 'kleistad_cursus';

  /**
   * User meta variabele welke alle cursus inschrijvingen bevat
   */
  const CONTACTINFO = 'contactinfo';

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
      add_filter('wp_mail_from', function($old) { return $this->from_email; });
      add_filter('wp_mail_from_name', function($old) { return 'Kleistad'; });

      add_shortcode('kleistad_rapport', [$this, 'rapport_handler']);
      add_shortcode('kleistad_saldo', [$this, 'saldo_handler']);
      add_shortcode('kleistad_saldo_overzicht', [$this, 'saldo_overzicht_handler']);
      add_shortcode('kleistad_stookbestand', [$this, 'stookbestand_handler']);
      add_shortcode('kleistad', [$this, 'reservering_handler']);

      add_shortcode('kleistad_cursus_inschrijving', [$this, 'cursus_inschrijving_handler']);
      add_shortcode('kleistad_cursus_beheer', [$this, 'cursus_beheer_handler']);
      add_shortcode('kleistad_betalingen', [$this, 'betalingen_handler']);
      add_shortcode('kleistad_registratie', [$this, 'registratie_handler']);
      add_shortcode('kleistad_registratie_overzicht', [$this, 'registratie_overzicht_handler']);
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

      dbDelta("CREATE TABLE {$wpdb->prefix}kleistad_cursussen (
                id int(10) NOT NULL AUTO_INCREMENT, 
                naam tinytext,
                start_datum date,
                eind_datum date,
                start_tijd time,
                eind_tijd time,
                docent tinytext,
                technieken tinytext,
                vervallen tinyint(1) DEFAULT 0,
                vol tinyint(1) DEFAULT 0,
                techniekkeuze tinyint(1) DEFAULT 0,
                inschrijfkosten numeric(10,2),
                cursuskosten numeric(10,2),
                inschrijfslug tinytext,
                indelingslug tinytext,
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
    wp_register_script(
      'time-entry-plugin', plugins_url('../vendor/timeentry/jquery.plugin.js', __FILE__), ['jquery']
    );
    wp_register_script(
      'time-entry', plugins_url('../vendor/timeentry/jquery.timeentry.js', __FILE__), ['jquery']
    );
    wp_register_style(
      'kleistad-css', plugins_url('../css/kleistad.css', __FILE__)
    );
    wp_register_style(
      'jqueryui-css', "//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css"
    );
    wp_register_style(
      'time-entry', plugins_url('../vendor/timeentry/jquery.timeentry.css', __FILE__)
    );
    wp_localize_script(
      'kleistad-js', 'kleistad_data', [
        'nonce' => wp_create_nonce('wp_rest'),
        'base_url' => rest_url($this->url),
        'success_message' => 'de reservering is geslaagd!',
        'error_message' => 'het was niet mogelijk om de reservering uit te voeren',
      ]
    );
  }

  private function enqueue_scripts() {
    wp_enqueue_script('jquery-ui-dialog');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-tooltip');
    wp_enqueue_script('time-entry-plugin');
    wp_enqueue_script('time-entry');
    wp_enqueue_script('kleistad-js');
    wp_enqueue_style('kleistad-css');
    wp_enqueue_style('jqueryui-css');
    wp_enqueue_style('time-entry');
  }
  
  /**
   * 
   * admin instellingen scherm
   */
  public function instellingen() {
    global $wpdb;

    if (!is_null(filter_input(INPUT_POST, 'kleistad_ovens_verzonden'))) {
      $naam = filter_input(INPUT_POST, 'kleistad_oven_naam', FILTER_SANITIZE_STRING);
      $tarief = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_oven_tarief', SANITIZE_NUMBER_FLOAT));
      $wpdb->insert("{$wpdb->prefix}kleistad_ovens", ['naam' => $naam, 'kosten' => $tarief], ['%s', '%s']);
    }

    if (!is_null(filter_input(INPUT_POST, 'kleistad_regeling_verzonden'))) {
      $gebruiker_id = filter_input(INPUT_POST, 'kleistad_regeling_gebruiker_id', FILTER_SANITIZE_NUMBER_INT);
      $oven_id = filter_input(INPUT_POST, 'kleistad_regeling_id', FILTER_SANITIZE_NUMBER_INT);
      $tarief = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_regeling_tarief', FILTER_SANITIZE_STRING));
      $this->maak_regeling($gebruiker_id, $oven_id, $tarief);
    }

    if (!is_null(filter_input(INPUT_POST, 'kleistad_saldo_verzonden'))) {
      $gebruiker_id = filter_input(INPUT_POST, 'kleistad_saldo_gebruiker_id', FILTER_SANITIZE_NUMBER_INT);
      $saldo = str_replace(",", ".", filter_input(INPUT_POST, 'kleistad_saldo_wijzigen', FILTER_SANITIZE_STRING));
      update_user_meta($gebruiker_id, 'stooksaldo', $saldo);
    }
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
                  if ($regelingen < 0) {
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
        <form action="<?php echo get_permalink() ?>" method="POST" >
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
    </div>
    <?php
  }
  /**
   * helper functie, maak een nonce field, maar dan zonder id (fout in $this->nonce_field functie)
   * 
   */
  private function nonce_field($actie) {
    return '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce($actie) . '" />';
  }

  /**
   * helper functie, haalt email tekst vanuit pagina en vervangt alle placeholders en verzendt de mail
   * @param string $to
   * @param string $subject
   * @param string $slug (pagina titel, als die niet bestaat wordt verondersteld dat de slug de bericht tekst bevat)
   * @param array $args
   * @param string $attachment
   */
  private function compose_email($to, $subject, $slug, $args = [], $attachment = []) {
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "From: Kleistad <$this->from_email>";

    $page = get_page_by_title($slug, OBJECT);
    if (!is_null($page)) {
      $text = apply_filters('the_content', $page->post_content);
      foreach ($args as $key => $value) {
        $text = str_replace('[' . $key . ']', $value, $text);
      }
      $fields = ['cc', 'bcc'];
      foreach ($fields as $field) {
        $gevonden = stripos ($text, '[' . $field . ':');
        if (!($gevonden === false)) {
          $eind = stripos($text,']', $gevonden);
          $adres = substr($text, $gevonden + 1, $eind - $gevonden - 1);
          $text = substr($text, 0, $gevonden) . substr($text, $eind + 1);
          $headers[] = ucfirst($adres);
        }
      }
    } else {
      $text = $slug;
    }
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
            <td align="left" style="font-family:helvetica; font-size:13pt" >' . preg_replace('/\s+/', ' ', $text) . '<br /><p>Met vriendelijke groet,</p>
          <p>Kleistad</p><p><a href="mailto:' . $this->info_email . '" target="_top">' . $this->info_email . '</a></p></td>                         
          </tr>
          <tr>
            <td align="center" style="font-family:calibri; font-size:9pt" >Deze e-mail is automatisch gegenereerd en kan niet beantwoord worden.</td>
          </tr></table></body>
          </html>';
    $resultaat = wp_mail($to, $subject, $htmlmessage, $headers, $attachment);
    return $resultaat;
  }

  /**
   * helper functie, zorg dat nederlandse format gebruikt wordt voor datums etc.
   */
  private function setlocale_NL() {
    setlocale(LC_TIME, 'NLD_nld', 'nl_NL', 'nld_nld', 'Dutch', 'nl_NL.utf8');
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
      if (user_can($gebruiker->id, self::RESERVEER)) {
        $saldo = number_format((float) get_user_meta($gebruiker->id, 'stooksaldo', true), 2, ',', '');
        $html .= "<tr><td>$gebruiker->display_name</td><td>&euro; $saldo</td></tr>";
      }
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
                    ORDER BY jaar DESC, maand DESC, dag DESC");
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
    $html .= "</tbody>
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
      $vanaf_datum = date('Y-m-d', strtotime(filter_input(INPUT_POST, 'kleistad_vanaf_datum', FILTER_SANITIZE_STRING)));
      $tot_datum = date('Y-m-d', strtotime(filter_input(INPUT_POST, 'kleistad_tot_datum', FILTER_SANITIZE_STRING)));
      $gebruiker = get_userdata(filter_input(INPUT_POST, 'kleistad_gebruiker_id', FILTER_SANITIZE_NUMBER_INT));

      $upload_dir = wp_upload_dir();
      $bijlage = $upload_dir['basedir'] . '/stookbestand_' . date('Y_m_d') . '.csv';
      $f = fopen($bijlage, 'w');

      global $wpdb;
      $stoken = $wpdb->get_results(
              "SELECT RE.id AS id, oven_id, naam, kosten, soortstook, temperatuur, programma,gebruiker_id, dag, maand, jaar, verdeling, verwerkt FROM
                    {$wpdb->prefix}kleistad_reserveringen RE, {$wpdb->prefix}kleistad_ovens OV
                WHERE RE.oven_id = OV.id AND str_to_date(concat(jaar,'-',maand,'-',dag),'%Y-%m-%d') BETWEEN '$vanaf_datum' AND '$tot_datum'
                        ORDER BY jaar ASC, maand ASC, dag ASC");
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
      $attachments = [$bijlage];
      if ($this->compose_email($to, "Kleistad stookbestand $vanaf_datum - $tot_datum", $message, [], $attachments)) {
        $html = '<div><p>Het bestand is per email verzonden.</p></div>';
      } else {
        $html = 'Er is een fout opgetreden';
      }
    } else {
      $huidige_gebruiker_id = get_current_user_id();
      $html = '<form action="' . get_permalink() . '" method="POST" >
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
      $gebruiker_id = filter_input(INPUT_POST, 'kleistad_gebruiker_id', FILTER_SANITIZE_NUMBER_INT);
      $saldo = number_format((float) get_user_meta($gebruiker_id, 'stooksaldo', true), 2, ',', '');
    }
    /*
     * Het onderstaande moet voorkomen dat iemand door een pagina refresh opnieuw melding maakt van een saldo storting
     */
    if (!is_null(filter_input(INPUT_POST, 'kleistad_saldo_verzonden')) && wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_saldo' . $gebruiker_id . $saldo)) {
      $via = filter_input(INPUT_POST, 'kleistad_via', FILTER_SANITIZE_STRING);
      $bedrag = filter_input(INPUT_POST, 'kleistad_bedrag', FILTER_SANITIZE_NUMBER_FLOAT);
      $datum = strftime('%d-%m-%Y', strtotime(filter_input(INPUT_POST, 'kleistad_datum', FILTER_SANITIZE_STRING)));
      $gebruiker = get_userdata($gebruiker_id);

      $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
      if ($this->compose_email($to, 'wijziging stooksaldo', 'kleistad_email_saldo_wijziging', 
              ['datum' => $datum, 'via' => $via, 'bedrag' => $bedrag, 'voornaam' => $gebruiker->first_name, 'achternaam' => $gebruiker->last_name, ] )) {
        $huidig = (float) get_user_meta($gebruiker_id, 'stooksaldo', true);
        $saldo = $bedrag + $huidig;
        update_user_meta($gebruiker->ID, 'stooksaldo', $saldo);
        $this->log_saldo("wijziging saldo $gebruiker->display_name van $huidig naar $saldo, betaling per $via.");
        $html = "<div><p>Het saldo is bijgewerkt naar &euro; $saldo en een email is verzonden.</p></div>";
      } else {
        $html = '<div><p>Er is een fout opgetreden want de email kon niet verzonden worden</p></div>';
      }
    } else {
      $datum = date('Y-m-d');
      $huidige_gebruiker_id = get_current_user_id();
      $saldo = number_format((float) get_user_meta($huidige_gebruiker_id, 'stooksaldo', true), 2, ',', '');
      $html = '<p>Je huidige stooksaldo is <strong>&euro; ' . $saldo . '</strong></p>
        <p>Je kunt onderstaand melden dat je het saldo hebt aangevuld</p><hr />
        <form action="' . get_permalink() . '" method="POST">';
      $html .= $this->nonce_field('kleistad_saldo' . $huidige_gebruiker_id . $saldo);
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
    $this->enqueue_scripts();
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
                    data-oven_id=\"$oven\" data-oven-naam=\"$naam\" data-maand=\"" . date('n') . "\" data-jaar=\"" . date('Y') . "\" >
                    <tr><th>de reserveringen worden opgehaald...</th></tr>
                </table>";
      if (!self::$reserveren_form) {
        self::$reserveren_form = true;
        $html .= "<div id =\"kleistad_oven\" class=\"kleistad_form_popup\">
                    <form id=\"kleistad_form\" action=\"#\" method=\"post\">
                    <input id=\"kleistad_oven_id\" type=\"hidden\" >
                    <table class=\"kleistad_form\">
                    <thead>
                        <tr>
                        <th colspan=\"3\">Reserveer de oven op <span id=\"kleistad_wanneer\"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                            <tr><td></td>";
        if ($this->override()) {
          $html .= "<td colspan=\"2\"><select id=\"kleistad_gebruiker_id\" class=\"kleistad_gebruiker\" >";
          foreach ($gebruikers as $gebruiker) {
            if (user_can($gebruiker->id, self::RESERVEER)) {
              $selected = ($gebruiker->id == $huidige_gebruiker->ID) ? "selected" : "";
              $html .= "<option value=\"{$gebruiker->id}\" $selected>{$gebruiker->display_name}</option>";
            }
          }
          $html .= "</select></td>";
        } else {
          $html .= "<td colspan=\"2\"><input type =\"hidden\" id=\"kleistad_gebruiker_id\" ></td>";
        }
        $html .= "</tr>
                    <tr>
                        <td><label>Soort stook</label></td>
                        <td colspan=\"2\"><select id=\"kleistad_soortstook\">
                            <option value=\"Biscuit\" selected>Biscuit</option>
                            <option value=\"Glazuur\" >Glazuur</option>
                            <option value=\"Overig\" >Overig</option>
                        </select></td>
                    </tr>
                    <tr>
                        <td><label>Temperatuur</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"100\" max=\"1300\" id=\"kleistad_temperatuur\"></td>
                    </tr>
                    <tr>
                        <td><label>Programma</label></td>
                        <td colspan=\"2\"><input type=\"number\" min=\"1\" max=\"19\" id=\"kleistad_programma\"></td>
                    </tr>
                    <tr>
                        <td><label>Tijdstip stoken</label></td>
                        <td colspan=\"2\"><select id=\"kleistad_opmerking\">
                                <option value=\"voor 13:00 uur\" >voor 13:00 uur</option>
                                <option value=\"tussen 13:00 en 16:00 uur\" >tussen 13:00 en 16:00 uur</option>
                                <option value=\"na 16:00 uur\" >na 16:00 uur</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><label>Stoker</label></td>
                        <td><span id=\"kleistad_stoker\">$huidige_gebruiker->display_name</span> <input type=\"hidden\" id=\"kleistad_1e_stoker\" name=\"kleistad_stoker_id\" value=\"$huidige_gebruiker->ID\"/></td>
                        <td><input type=\"number\" name=\"kleistad_stoker_perc\" readonly /> %</td>
                    </tr>";
        for ($i = 2; $i <= 5; $i++) {
          $html .= "<tr>
                          <td><label>Stoker</label></td>
                          <td><select name=\"kleistad_stoker_id\" class=\"kleistad_verdeel\" >
                          <option value=\"0\" >&nbsp;</option>";
          foreach ($gebruikers as $gebruiker) {
            if (user_can($gebruiker->id, self::RESERVEER) AND ($gebruiker->id <> $huidige_gebruiker->ID) || $this->override()) {
              $html .= "<option value=\"{$gebruiker->id}\">{$gebruiker->display_name}</option>";
            }
          }
          $html .= "</select></td>
                      <td><input type=\"number\" class=\"kleistad_verdeel\" name=\"kleistad_stoker_perc\" min=\"0\" max=\"100\" > %</td>
                  </tr>";
        }
        $html .= "</tbody>
                  <tfoot>
                      <tr>
                          <th colspan=\"3\">
                          <input type =\"hidden\" id=\"kleistad_dag\">
                          <input type =\"hidden\" id=\"kleistad_maand\">
                          <input type =\"hidden\" id=\"kleistad_jaar\">
                          <span id=\"kleistad_tekst\"></span></th>
                      </tr>
                      <tr>
                          <th><button type=\"button\" id=\"kleistad_muteer\" class=\"kleistad_muteer\" >Wijzig</button></th>
                          <th><button type=\"button\" id=\"kleistad_verwijder\" class=\"kleistad_verwijder\" >Verwijder</button></th>
                          <th><button type=\"button\" id=\"kleistad_sluit\" class=\"kleistad_sluit\" >Sluit</button></th>
                      </tr>
                  </tfoot>
              </table>
              </form>
          </div>";
      }
      return $html;
    } else {
      return "<p>de shortcode bevat geen oven nummer tussen 1 en 999 !</p>";
    }
  }

  /**
   * help functie, valideer en bewaar een gebruiker registratie.
   * 
   * @return string of WP_Error object
   */
  private function registreer_gebruiker($gebruiker_id = 0) {
    // aanmelding voornaam, achternaam, emailadres. voornaam+volgnummer wordt gebruikersnaam
    $error = new WP_Error();
    $input = filter_input_array(INPUT_POST, [
        'emailadres' => FILTER_SANITIZE_EMAIL,
        'voornaam' => FILTER_SANITIZE_STRING,
        'achternaam' => FILTER_SANITIZE_STRING,]);

    $contactinfo = filter_input_array(INPUT_POST, [
        'straat' => FILTER_SANITIZE_STRING,
        'pcode' => FILTER_SANITIZE_STRING,
        'huisnr' => FILTER_SANITIZE_STRING,
        'plaats' => FILTER_SANITIZE_STRING,
        'telnr' => FILTER_SANITIZE_STRING,
    ]);
    $input['emailadres'] = strtolower($input['emailadres']);
    if (!filter_var($input['emailadres'], FILTER_VALIDATE_EMAIL)) {
      $error->add('verplicht', 'Een E-mail adres is verplicht');
    }
    $contactinfo['pcode'] = preg_replace('/\s+/', '', strtoupper($contactinfo['pcode']));
    if (!filter_var($contactinfo['pcode'], FILTER_VALIDATE_REGEXP, ['options' => [ 'regexp'=> '/^[1-9][0-9]{3} ?(?!SA|SD|SS)[A-Z]{2}$/i' ] ])) {
      $error->add('verplicht', 'Een geldige postcode is verplicht');
    }
    if (!$input['voornaam']) {
      $error->add('verplicht', 'Een voornaam is verplicht');
    }
    if (!$input['achternaam']) {
      $error->add('verplicht', 'Een achternaam is verplicht');
    }
    $err = $error->get_error_codes();
    if (!empty($err)) {
      return $error;
    }
    if ($gebruiker_id == 0) {
      $gebruiker_id = email_exists($input['emailadres']);
      if ($gebruiker_id) {
        $gebruiker_info = get_userdata($gebruiker_id);
        if (!empty($gebruiker_info->roles) or (is_array($gebruiker_info->roles) and (count($gebruiker_info->roles) > 0 ) ) ) {
          $error->add('account_aanwezig', 'Dit emailadres is al geregistreerd. U moet inloggen om een cursus aan te vragen');
          return $error;
        }
      } else {
        $uniek = '';
        $naam = sanitize_user($input['voornaam']);
        while (username_exists($naam . $uniek)) {
          $uniek = intval($uniek) + 1;
        }
        $paswoord = wp_generate_password(12, true);
        $gebruiker_id = wp_create_user($naam . $uniek, $paswoord, $input['emailadres']);
      }
      wp_update_user(['ID' => $gebruiker_id, 'first_name' => $input['voornaam'], 'last_name' => $input['achternaam'],
          'display_name' => $input['voornaam'] . ' ' . $input['achternaam'], 'role' => '']);
    } else {
      wp_update_user(['ID' => $gebruiker_id, 'first_name' => $input['voornaam'], 'last_name' => $input['achternaam'],
          'display_name' => $input['voornaam'] . ' ' . $input['achternaam'], 'user_email' => $input['emailadres']]);
    }
    update_user_meta($gebruiker_id, self::CONTACTINFO, $contactinfo);
    return $gebruiker_id;
  }

  /**
   * help functie registreer de cursist als deze niet ingelogd is en registreer de cursus keuze
   * 
   * @return true of wp_error object
   */
  private function registreer_cursus_inschrijving() {
    global $wpdb;
    $error = new WP_Error();
    $input = filter_input_array(INPUT_POST, [
        '_wpnonce' => FILTER_DEFAULT,
        'cursus_id' => FILTER_SANITIZE_NUMBER_INT,
        'technieken' => ['filter' => FILTER_SANITIZE_STRING, 'flags' => FILTER_FORCE_ARRAY],
        'opmerking' => FILTER_SANITIZE_STRING,
    ]);

    if (wp_verify_nonce($input['_wpnonce'], 'kleistad_cursus_inschrijving')) {
      if (!is_user_logged_in()) {
        $resultaat = $this->registreer_gebruiker();
        if (is_wp_error($resultaat)) {
          return $resultaat;
        } else {
          $gebruiker_id = $resultaat;
        }
      } else {
        $gebruiker_id = get_current_user_id();
      }
      if (intval($input['cursus_id']) == 0) {
        $error->add('verplicht', 'Er is nog geen cursus gekozen');
        return $error;
      } else {
        $cursus_id = $input['cursus_id'];
      }

      $cursus = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}kleistad_cursussen WHERE id=" . $input['cursus_id']);
      if (is_null($cursus)) {
        $error->add('onbekend', 'De gekozen cursus is onbekend. Misschien is er iets fout gegaan ?');
        return $error;
      }

      $code = 'C' . $cursus_id . '-' . $gebruiker_id . '-' . strftime('%y%m%d', strtotime($cursus->start_datum));
      $nieuwe_inschrijving = ['code' => $code, 'datum' => date('d-m-Y'), 'technieken' => $input['technieken'],
          'i_betaald' => 0, 'c_betaald' => 0, 'ingedeeld' => 0, 'bericht' => 0, 'opmerking' => $input['opmerking']];
      $inschrijvingen = get_user_meta($gebruiker_id, self::INSCHRIJVINGEN, true);
      if (is_array($inschrijvingen) AND array_key_exists($cursus_id, $inschrijvingen)) {
        if ($inschrijvingen[$cursus_id]['ingedeeld']) {
          $error->add('ingedeeld', 'Je bent al ingedeeld op deze cursus');
          return $error;
        }
        $code = $inschrijvingen[$cursus_id]['code'];
        $inschrijvingen[$cursus_id]['technieksn'] = $input['technieken'];
        $inschrijvingen[$cursus_id]['opmerking'] = $input['opmerking'];
      } else {
        $inschrijvingen[$cursus_id] = $nieuwe_inschrijving;
      }
      update_user_meta($gebruiker_id, self::INSCHRIJVINGEN, $inschrijvingen);

      $gebruiker = get_userdata($gebruiker_id);
      $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
      $technieken = $inschrijvingen[$cursus_id]['technieken'];
      return $this->compose_email($to, 'inschrijving cursus', $cursus->inschrijfslug, [
          'voornaam' => $gebruiker->first_name,
          'achternaam' => $gebruiker->last_name,
          'cursus_naam' => $cursus->naam,
          'cursus_docent' => $cursus->docent,
          'cursus_start_datum' => strftime('%A %d-%m-%y', strtotime($cursus->start_datum)),
          'cursus_eind_datum' => strftime('%A %d-%m-%y', strtotime($cursus->eind_datum)),
          'cursus_start_tijd' => strftime('%H:%M', strtotime($cursus->start_tijd)),
          'cursus_eind_tijd' => strftime('%H:%M', strtotime($cursus->eind_tijd)),
          'cursus_technieken' => is_array($technieken) ? implode(', ', $inschrijvingen[$cursus_id]['technieken']) : '',
          'cursus_opmerking' => $inschrijvingen[$cursus_id]['opmerking'],
          'cursus_code' => $inschrijvingen[$cursus_id]['code'],
          'cursus_kosten' => number_format($cursus->cursuskosten, 2, ',', ''),
          'cursus_inschrijfkosten' => number_format($cursus->inschrijfkosten, 2, ',', ''),
        ]);
    } else {
      $error->add('security', 'Er is een interne fout geconstateerd. Probeer het eventueel op een later moment opnieuw');
      return $error;
    }
  }

  /**
   * help functie, toont cursus formulier en naw velden indien niet ingelogd
   * 
   * @global type $wpdb   toegang tot wp db
   * @param type $tonen   bepaalt of waarden eerder ingevuld zijn
   * @return string       html form
   */
  private function toon_cursus_inschrijf_formulier($tonen = false) {
    global $wpdb;

    $cursussen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kleistad_cursussen WHERE eind_datum > CURDATE() ORDER BY start_datum");
    if ($wpdb->num_rows < 1) {
      return '<div class="kleistad_fout"><p>Helaas zijn alle cursussen volgeboekt</p></div>';
    }
    if ($tonen) {
      $input = filter_input_array(INPUT_POST, [
          'emailadres' => FILTER_SANITIZE_EMAIL,
          'voornaam' => FILTER_SANITIZE_STRING,
          'achternaam' => FILTER_SANITIZE_STRING,
          'straat' => FILTER_SANITIZE_STRING,
          'huisnr' => FILTER_SANITIZE_STRING,
          'pcode' => FILTER_SANITIZE_STRING,
          'plaats' => FILTER_SANITIZE_STRING,
          'telnr' => FILTER_SANITIZE_STRING,
          'cursus_keuze' => FILTER_SANITIZE_STRING,
          'opmerking' => FILTER_SANITIZE_STRING,
      ]);
    } else {
      $input = ['emailadres' => '', 'voornaam' => '', 'achternaam' => '', 'straat' => '', 'huisnr' => '', 'pcode' => '', 'plaats' => '', 'telnr' => '', 'cursus_keuze' => '', 'opmerking' => ''];
    }
    $html = '<form action="' . get_permalink() . '" method="POST">' .
            $this->nonce_field('kleistad_cursus_inschrijving') .
            '<table class="kleistad_form" >';

    foreach ($cursussen as $cursus) {
      $disabled = ' ';
      $checked = (($input['cursus_keuze'] == $cursus->id) ? 'checked ' : ' ');
      $naam = $cursus->naam . ', start ' . strftime('%A %d-%m-%y', strtotime($cursus->start_datum)) . ' ' . strftime('%H:%M', strtotime($cursus->start_tijd));
      if ($cursus->vervallen || $cursus->vol) {
        $naam .= ($cursus->vervallen ? ': vervallen' : ': vol');
        $disabled = 'disabled ';
      }
      $html .= '<tr><td style="text-align:center"><input type="radio" name="cursus_id" value="' . $cursus->id . '" ' . $checked . $disabled . 
              'data-technieken=\''. $cursus->technieken .'\' ></td><td colspan="3">' . $naam . '</td></tr>';
    }
    $html .= '<tr title="kies de techniek(en) die je wilt oefenen" ><td style="visibility:hidden" id="kleistad_cursus_technieken" >Techniek</td>
              <td style="visibility:hidden" id="kleistad_cursus_draaien" ><input type="checkbox" name="technieken[]" value="Draaien">Draaien</td>
              <td style="visibility:hidden" id="kleistad_cursus_handvormen" ><input type="checkbox" name="technieken[]" value="Handvormen">Handvormen</td>
              <td style="visibility:hidden" id="kleistad_cursus_boetseren" ><input type="checkbox" name="technieken[]" value="Boetseren">Boetseren</td>
            </tr>';

    if (!is_user_logged_in()) { 
      $html .= '<tr>
            <td><label for="kleistad_voornaam">Naam</label></td>
            <td><input type="text" name="voornaam" id="kleistad_voornaam" required maxlength="25" placeholder="voornaam" value="' . $input['voornaam'] . '" /></td>
            <td colspan="2" ><input type="text" name="achternaam" id="kleistad_achternaam" required maxlength="25" placeholder="achternaam" value="' . $input['achternaam'] . '" /></td>
          </tr>
          <tr>
            <td><label for="kleistad_emailadres">Email adres</label></td>
            <td colspan="3" ><input type="email" name="emailadres" id="kleistad_emailadres" required placeholder="mijnemailadres@voorbeeld.nl" value="' . $input['emailadres'] . '" /></td>
          </tr>
          <tr>
            <td><label for="kleistad_telnr">Telefoon</label></td>
            <td colspan="3" ><input type="text" name="telnr" id="kleistad_telnr" maxlength="15" placeholder="0123456789" value="' . $input['telnr'] . '" /></td>
          </tr>
          <tr>    
            <td><label for="kleistad_straat">Straat, nr</label></td>
            <td colspan="2" ><input type="text" name="straat" id="kleistad_straat" required placeholder="straat" maxlength="50" value="' . $input['straat'] . '" /></td>
            <td><input type="text" name="huisnr" id="kleistad_huisnr" required maxlength="10" placeholder="nr" value="' . $input['huisnr'] . '" /></td>
          </tr>
          <tr>
            <td><label for="kleistad_pcode">Postcode, Plaats</label></td>
            <td><input type="text" name="pcode" id="kleistad_pcode" required maxlength="10" placeholder="1234AB" value="' . $input['pcode'] . '" /></td>
            <td colspan="2" ><input type="text" name="plaats" id="kleistad_plaats" required maxlength="50" placeholder="MijnWoonplaats" value="' . $input['plaats'] . '" /></td>
          </tr>';
    }
    $html .= '<tr title="Wat is je ervaring met klei? Je kunt hier ook andere opmerkingen achterlaten die van belang zijn voor de cursus indeling" ><td><label for="kleistad_cursist_opmerking_veld">Opmerking</label></td>
              <td colspan="3" ><textarea name="opmerking" id="kleistad_cursist_opmerking_veld" rows="5" cols="50">' . $input['opmerking'] . '</textarea></td></tr>
      </table>
        <button type="submit" name="kleistad_cursus_inschrijving" id="kleistad_cursus_inschrijving" >Verzenden</button>
        </form>';
    return $html;
  }

  /**
   * help functie toont foutmeldingen na invullen formulier
   * 
   * @param wp_error_object $result 
   * @return string  html tekst
   */
  private function toon_fout($result) {
    $html = '';
    foreach ($result->get_error_messages() as $error) {
      $html .= '<div class="kleistad_fout"><p>' . $error . '</p></div>';
    }
    return $html;
  }

  /**
   * help functie toont succes melding na invullen formulier 
   * 
   * @return string   html tekst
   */
  private function toon_succes($mail = true) {
    if ($mail) {
      return '<div class="kleistad_succes"><p>De aanvraag is verwerkt. Je ontvangt een email ter bevestiging</p></div>';
    } else {
      return '<div class="kleistad_succes"><p>De gegevens zijn opgeslagen</p></div>';
    }
  }

  public function registratie_handler() {
    $this->enqueue_scripts();
    $html = '';

    $gebruiker = wp_get_current_user();
    if ($gebruiker->ID != 0) {
      $contactinfo = get_user_meta($gebruiker->ID, self::CONTACTINFO, true);
      if (!is_array($contactinfo)) {
        $contactinfo = [ 'straat' => '', 'huisnr' => '', 'pcode' => '', 'plaats' => '', 'telnr' => ''];
      }

      $html .= '<form action="' . get_permalink() . '" method="POST">' .
              $this->nonce_field('kleistad_wijzig_registratie') .
          '<table class="kleistad_form" >
            <tr>
              <td><label for="kleistad_voornaam">Naam</label></td>
              <td><input type="text" name="voornaam" id="kleistad_voornaam" required maxlength="25" placeholder="voornaam" value="' . $gebruiker->user_firstname . '" /></td>
              <td colspan="2" ><input type="text" name="achternaam" id="kleistad_achternaam" required maxlength="25" placeholder="achternaam" value="' . $gebruiker->user_lastname . '" /></td>
            </tr>
            <tr>
              <td><label for="kleistad_emailadres">Email adres</label></td>
              <td colspan="3" ><input type="email" name="emailadres" id="kleistad_emailadres" required value="' . $gebruiker->user_email . '" /></td>
            </tr>
            <tr>
              <td><label for="kleistad_telnr">Telefoon</label></td>
              <td colspan="3" ><input type="text" name="telnr" id="kleistad_telnr" maxlength="15" placeholder="0123456789" value="' . $contactinfo['telnr'] . '" /></td>
            </tr>
            <tr>    
              <td><label for="kleistad_straat">Straat, nr</label></td>
              <td colspan="2" ><input type="text" name="straat" id="kleistad_straat" required placeholder="straat" maxlength="50" value="' . $contactinfo['straat'] . '" /></td>
              <td><input type="text" name="huisnr" id="kleistad_huisnr" required maxlength="10" placeholder="nr" value="' . $contactinfo['huisnr'] . '" /></td>
            </tr>
            <tr>
              <td><label for="kleistad_pcode">Postcode, Plaats</label></td>
              <td><input type="text" name="pcode" id="kleistad_pcode" required maxlength="10" placeholder="1234AB" value="' . $contactinfo['pcode'] . '" /></td>
              <td colspan="2" ><input type="text" name="plaats" id="kleistad_plaats" required maxlength="50" placeholder="MijnWoonplaats" value="' . $contactinfo['plaats'] . '" /></td>
            </tr>
        </table>
        <button type="submit" name="kleistad_wijzig_registratie" id="kleistad_wijzig_registratie" >Opslaan</button>
        </form>';
      if (!is_null(filter_input(INPUT_POST, 'kleistad_wijzig_registratie'))) {
        $resultaat = $this->registreer_gebruiker($gebruiker->ID);
        $html .= (is_wp_error($resultaat)) ? $this->toon_fout($resultaat) : $this->toon_succes(false);
      }
    }
    return $html;
  }
  
  /**
   * shortcode handler voor het cursus inschrijf formulier
   *
   */
  public function cursus_inschrijving_handler() {
    $this->enqueue_scripts();
    $this->setlocale_NL();

    if (!is_null(filter_input(INPUT_POST, 'kleistad_cursus_inschrijving'))) {
      $resultaat = $this->registreer_cursus_inschrijving();
      if (is_wp_error($resultaat)) {
        return $this->toon_fout($resultaat) . $this->toon_cursus_inschrijf_formulier(true);
      }
      return $this->toon_succes() . $this->toon_cursus_inschrijf_formulier();
    }
    return $this->toon_cursus_inschrijf_formulier();
  }

  /**
   * helper functie toont gegevens formulier
   * 
   * @return string
   */
  private function toon_cursus_gegevens_formulier() {
    $html = '<div id="kleistad_cursus_gegevens" >
        <form id="kleistad_form_cursus_gegevens" action="#" method="post" >' . $this->nonce_field('kleistad_cursus_gegevens') .
            '<input type="hidden" name="id" id="kleistad_cursus_id_1" />
          <table class="kleistad_form" >
            <tr><th>Naam</th><td colspan="3"><input type="text" name="naam" id="kleistad_cursus_naam" placeholder="Bijv. cursus draaitechnieken" required /></td></tr>
            <tr><th>Docent</th><td colspan="3"><input type="text" name="docent" id="kleistad_cursus_docent" list="kleistad_docenten" >
            <datalist id="kleistad_docenten">';
    $gebruikers = get_users(['fields' => ['display_name'], 'orderby' => ['nicename']]);
    foreach ($gebruikers as $gebruiker) {
      $html .= '<option value="' . $gebruiker->display_name . '">';
    }
    $html .= '</datalist></td></tr>
              <tr><th>Start</th><td><input type="date" name="start_datum" id="kleistad_cursus_start_datum" class="kleistad-datum" required /></td>
              <th>Eind</th><td><input type="date" name="eind_datum" id="kleistad_cursus_eind_datum" class="kleistad-datum" min="' . date('Y-m-d') . '" required /></td></tr>
            <tr><th>Begintijd</th><td><input type="text" name="start_tijd" id="kleistad_cursus_start_tijd" placeholder="00:00" class="kleistad_tijd" /></td>
              <th>Eindtijd</th><td><input type="text" name="eind_tijd" id="kleistad_cursus_eind_tijd" placeholder="00:00" class="kleistad_tijd" /></td></tr>
            <tr><th>Technieken</th>
              <td><input type="checkbox" name="technieken[]" id="kleistad_draaien" value="Draaien">Draaien</td>
              <td><input type="checkbox" name="technieken[]" id="kleistad_handvormen" value="Handvormen">Handvormen</td>
              <td><input type="checkbox" name="technieken[]" id="kleistad_boetseren" value="Boetseren">Boetseren</td></tr>
            <tr><th>Inschrijf kosten</th><td><input type="number" name="inschrijfkosten" id="kleistad_inschrijfkosten" value="25" min="0" required ></td>
                <th>Cursus kosten, excl. inschrijf kosten</th><td><input type="number" name="cursuskosten" id="kleistad_cursuskosten" value="110" min="0" required ></td></tr>
            <tr><th>Cursus vol</th><td><input type="checkbox" id="kleistad_vol" name="vol" ></td><th>Cursus vervallen</th><td><input type="checkbox" name="vervallen" id="kleistad_vervallen" ></td></tr>
            <tr><th>Inschrijf email</th><td colspan="3"><input type="text" name="inschrijfslug" id="kleistad_inschrijfslug" value="kleistad_email_cursus_aanvraag" required /></td></tr>
            <tr><th>Indeling email</th><td colspan="3"><input type="text" name="indelingslug" id="kleistad_indelingslug" value="kleistad_email_cursus_ingedeeld" required /></td></tr>
          </table>
          <button type="submit" name="kleistad_bewaar_cursus_gegevens">Opslaan</button>
        </form>
      </div>';
    return $html;
  }

  /**
   * helper functie, valideer en registreer cursus gegevens
   * @return \WP_Error
   */
  private function registreer_cursus_gegevens() {
    global $wpdb;
    $error = new WP_Error();
    if (wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_cursus_gegevens')) {
      $input = filter_input_array(INPUT_POST, [
          'id' => FILTER_SANITIZE_NUMBER_INT,
          'naam' => FILTER_SANITIZE_STRING,
          'docent' => FILTER_SANITIZE_STRING,
          'start_datum' => FILTER_SANITIZE_STRING,
          'eind_datum' => FILTER_SANITIZE_STRING,
          'start_tijd' => FILTER_SANITIZE_STRING,
          'eind_tijd' => FILTER_SANITIZE_STRING,
          'techniekkeuze' => FILTER_SANITIZE_STRING,
          'vol' => FILTER_SANITIZE_STRING,
          'vervallen' => FILTER_SANITIZE_STRING,
          'inschrijfkosten' => ['filter' => FILTER_SANITIZE_NUMBER_FLOAT, 'flags' => FILTER_FLAG_ALLOW_FRACTION],
          'cursuskosten' => ['filter' => FILTER_SANITIZE_NUMBER_FLOAT, 'flags' => FILTER_FLAG_ALLOW_FRACTION],
          'inschrijfslug' => FILTER_SANITIZE_STRING,
          'indelingslug' => FILTER_SANITIZE_STRING,
      ]);
      $input['technieken'] = json_encode(filter_input(INPUT_POST, 'technieken', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY));
      $input['vol'] = ($input['vol'] != '' ? 1 : 0);
      $input['vervallen'] = ($input['vervallen'] != '' ? 1 : 0);
      $wpdb->replace("{$wpdb->prefix}kleistad_cursussen", $input);
    } else {
      $error->add('security', 'Er is een interne fout geconstateerd. Probeer het eventueel op een later moment opnieuw');
      return $error;
    }
  }

  /**
   * helper functie, toont indeling formulier
   * 
   * @return string
   */
  private function toon_cursus_indeling_formulier() {
    $html = '<div id="kleistad_cursus_indeling" >
        <form id="kleistad_form_cursus_indeling" action="#" method="post" >' . $this->nonce_field('kleistad_cursus_indeling') .
            '<input type="hidden" name="cursus_id" id="kleistad_cursus_id_2" />
             <input type="hidden" name="indeling_lijst" id="kleistad_indeling_lijst" /> 
          <table class="kleistad_form" >
            <tr><th>Wachtlijst</th><td></td><th>Indeling</th></tr>
            <tr><td><select style="height:200px" size="10" id="kleistad_wachtlijst" ></select></td>
              <td><button id="kleistad_wissel_indeling">&lt;-&gt;</button></td>
              <td><select style="height:200px" size="10" id="kleistad_indeling" ></select></td>
            </tr>
          </table>
          <div id="kleistad_cursist_technieken"></div>
          <div id="kleistad_cursist_opmerking"></div>
          <button type="submit" name="kleistad_bewaar_cursus_indeling" id="kleistad_bewaar_cursus_indeling" >Opslaan</button>
        </form>
      </div>';
    return $html;
  }

  /**
   * helper functie, valideer en registreer indeling
   * 
   * @return string
   */
  private function registreer_cursus_indeling() {
    global $wpdb;
    $error = new WP_Error();
    if (wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_cursus_indeling')) {
      $cursisten = json_decode(filter_input(INPUT_POST, 'indeling_lijst', FILTER_SANITIZE_STRING), true);
      $cursus_id = filter_input(INPUT_POST, 'cursus_id', FILTER_SANITIZE_NUMBER_INT);
      $cursus = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}kleistad_cursussen WHERE id= $cursus_id");
      if (count($cursisten > 0)) {
        foreach ($cursisten as $cursist_id) {
          $inschrijvingen = get_user_meta($cursist_id, self::INSCHRIJVINGEN, true);
          if ($inschrijvingen[$cursus_id]['ingedeeld'] == 0) {
            $gebruiker = get_userdata($cursist_id);
            $technieken = $inschrijvingen[$cursus_id]['technieken'];
            $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
            $this->compose_email($to, 'inschrijving cursus', $cursus->indelingslug, [
                'voornaam' => $gebruiker->first_name,
                'achternaam' => $gebruiker->last_name,
                'cursus_naam' => $cursus->naam,
                'cursus_docent' => $cursus->docent,
                'cursus_start_datum' => strftime('%A %d-%m-%y', strtotime($cursus->start_datum)),
                'cursus_eind_datum' => strftime('%A %d-%m-%y', strtotime($cursus->eind_datum)),
                'cursus_start_tijd' => strftime('%H:%M', strtotime($cursus->start_tijd)),
                'cursus_eind_tijd' => strftime('%H:%M', strtotime($cursus->eind_tijd)),
                'cursus_technieken' => is_array($technieken) ? implode(', ', $technieken) : '',
                'cursus_code' => $inschrijvingen[$cursus_id]['code'],
                'cursus_kosten' => number_format($cursus->cursuskosten, 2, ',', ''),
                'cursus_inschrijfkosten' => number_format($cursus->inschrijfkosten, 2, ',', ''),
            ]);
            $inschrijvingen[$cursus_id]['ingedeeld'] = 1;
            update_user_meta($cursist_id, self::INSCHRIJVINGEN, $inschrijvingen);
          }
        }
      }
    } else {
      $error->add('security', 'Er is een interne fout geconstateerd. Probeer het eventueel op een later moment opnieuw');
      return $error;
    }
  }

  /**
   * helper functie, toont de openstaande cursussen, voor de cursus beheerder
   * 
   * @global type $wpdb
   * @return string
   */
  private function toon_openstaande_cursussen() {
    global $wpdb;
    $html = '<table class="kleistad_tabel">
      <thead>
        <tr><th>Code</th><th>Naam</th><th>Docent</th><th>Periode</th><th>Tijd</th><th>Technieken</th></tr>
      </thead>
      <tbody>';

    $cursussen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kleistad_cursussen WHERE eind_datum >= CURDATE() ORDER BY start_datum DESC");
    $inschrijvers = get_users(['meta_key' => self::INSCHRIJVINGEN]);
    
    foreach ($cursussen as $cursus) {
      $wachtlijst = [];
      $ingedeeld = [];

      foreach ($inschrijvers as $inschrijver) {
        $inschrijvingen = get_user_meta($inschrijver->ID, self::INSCHRIJVINGEN, true);
        if (array_key_exists($cursus->id, $inschrijvingen)) {
          $element = [
                'naam' => $inschrijver->display_name, 
                'opmerking' => $inschrijvingen[$cursus->id]['opmerking'], 
                'technieken' => $inschrijvingen[$cursus->id]['technieken'],
                'ingedeeld' => $inschrijvingen[$cursus->id]['ingedeeld'],
                'id' => $inschrijver->ID,
              ];
          if ($inschrijvingen[$cursus->id]['ingedeeld']) {
            $ingedeeld[$inschrijver->ID] = $element;
          } elseif ($inschrijvingen[$cursus->id]['i_betaald']) {
            $wachtlijst[$inschrijver->ID] = $element;
          }
        }
      }
      $style = $cursus->vol ? 'style="background-color:lightblue"' : ($cursus->vervallen ? 'style="background-color:lightgray"' : '');
      $html .= '<tr ' . $style . ' class="kleistad_cursus_info"' . 
            ' data-cursus=\'' . json_encode($cursus) . '\' data-wachtlijst= \'' . json_encode($wachtlijst) . '\' data-ingedeeld= \'' . json_encode($ingedeeld) . '\' >
              <td>C' . $cursus->id . '</td><td>' . $cursus->naam . '</td>
              <td>' . $cursus->docent . '</td>
              <td>' . strftime('%d-%m', strtotime($cursus->start_datum)) . ' .. ' . strftime('%d-%m', strtotime($cursus->eind_datum)) . '</td>
              <td>' . strftime('%H:%M', strtotime($cursus->start_tijd)) . ' - ' . strftime('%H:%M', strtotime($cursus->eind_tijd)) . '</td>
              <td>';
      $technieken = json_decode ($cursus->technieken, true);
      if (is_array($technieken)) {
        foreach ($technieken as $techniek) {
          $html .= $techniek . '<br/>';
        }
      }
      $html .= '</td></tr>';
    }
    $html .= '</tbody></table>';
    return $html;
  }

  /**
   * shortcode handler voor het aanmaken, wijzigen of laten vervallen van cursussen en het indelen van de cursisten
   * 
   * @param type $atts
   */
  public function cursus_beheer_handler() {
    if (!$this->override()) {
      return '';
    }
    $this->enqueue_scripts();
    $this->setlocale_NL();

    $html = '';
    if (!is_null(filter_input(INPUT_POST, 'kleistad_bewaar_cursus_gegevens'))) {
      $resultaat = $this->registreer_cursus_gegevens();
      $html .= (is_wp_error($resultaat)) ? $this->toon_fout($resultaat) : $this->toon_succes(false);
    }

    if (!is_null(filter_input(INPUT_POST, 'kleistad_bewaar_cursus_indeling'))) {
      $resultaat = $this->registreer_cursus_indeling();
      $html .= (is_wp_error($resultaat)) ? $this->toon_fout($resultaat) : $this->toon_succes(false);
    }

    $html .= '<div id="kleistad_cursus">
        <div id="kleistad_cursus_tabs">
          <ul>
            <li><a href="#kleistad_cursus_gegevens">Cursus informatie</a></li>
            <li><a href="#kleistad_cursus_indeling">Cursus indeling</a></li>
          </ul>' . $this->toon_cursus_gegevens_formulier() . $this->toon_cursus_indeling_formulier() . '
        </div>
      </div>' . $this->toon_openstaande_cursussen() . '<button id="kleistad_cursus_toevoegen" >Toevoegen</button>';

    return $html;
  }

  /**
   * helper functie, toont cursisten die nog betalingen hebben openstaand
   * 
   * @return string
   */
  private function registreer_betalingen() {
    $error = new WP_Error();
    if (wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_betalingen')) {
      $i_betalingen = filter_input(INPUT_POST, 'kleistad_i_betaald', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY);
      $i_betalingen_lijst = [];
      if (!is_null($i_betalingen)) {
        foreach ($i_betalingen as $i_betaald) {
          $atts = explode(' ', $i_betaald);
          $cursist_id = intval($atts[0]);
          $cursus_id = intval($atts[1]);
          $i_betalingen_lijst[$cursist_id][$cursus_id] = 1;
        }
      }
      $c_betalingen = filter_input(INPUT_POST, 'kleistad_c_betaald', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY);
      $c_betalingen_lijst = [];
      if (!is_null($c_betalingen)) {
        foreach ($c_betalingen as $c_betaald) {
          $atts = explode(' ', $c_betaald);
          $cursist_id = intval($atts[0]);
          $cursus_id = intval($atts[1]);
          $c_betalingen_lijst[$cursist_id][$cursus_id] = 1;
        }
      }
      $inschrijvers = get_users(['meta_key' => self::INSCHRIJVINGEN]);
      foreach ($inschrijvers as $inschrijver) {
        $inschrijvingen = get_user_meta($inschrijver->ID, self::INSCHRIJVINGEN, true);
        foreach ($inschrijvingen as $cursus_id => &$inschrijving) {
          if (array_key_exists($inschrijver->ID, $c_betalingen_lijst) AND array_key_exists($cursus_id, $c_betalingen_lijst[$inschrijver->ID])) {
            $inschrijving['c_betaald'] = 1;
          }
          if (array_key_exists($inschrijver->ID, $i_betalingen_lijst) AND array_key_exists($cursus_id, $i_betalingen_lijst[$inschrijver->ID])) {
            $inschrijving['i_betaald'] = 1;
          }
        }
        update_user_meta($inschrijver->ID, self::INSCHRIJVINGEN, $inschrijvingen);
      }
      return true;
    } else {
      $error->add('security', 'Er is een interne fout geconstateerd. Probeer het eventueel op een later moment opnieuw');
      return $error;
    }
  }

  /**
   * shortcode handler voor het uitlijsten en verwerken van alle openstaande betaalstatussen, voorlopig alleen inschrijf- en cursusgeld
   * 
   * @param type $atts
   */
  public function betalingen_handler() {
    if (!$this->override()) {
      return '';
    }
    $this->enqueue_scripts();
    $this->setlocale_NL();

    global $wpdb;
    $cursussen = $wpdb->get_results("SELECT id, naam FROM {$wpdb->prefix}kleistad_cursussen WHERE eind_datum >= CURDATE()", OBJECT_K);

    $html = '';
    if (!is_null(filter_input(INPUT_POST, 'kleistad_registreer_betalingen', FILTER_SANITIZE_STRING))) {
      $resultaat = $this->registreer_betalingen();
      $html .= (is_wp_error($resultaat)) ? $this->toon_fout($resultaat) : $this->toon_succes(false);
    }

    $html .= '<form id="kleistad_form_betalingen" action="#" method="post" >' . $this->nonce_field('kleistad_betalingen') .
            '<table class="kleistad_tabel" >
          <tr><th>Datum<br/>inschrijving</th><th>Code</th><th>Naam</th><th>Inschrijfgeld<br/>betaald</th><th>Cursusgeld<br/>betaald</th></tr>';
    $inschrijvers = get_users(['meta_key' => self::INSCHRIJVINGEN]);
    foreach ($inschrijvers as $inschrijver) {
      $inschrijvingen = get_user_meta($inschrijver->ID, self::INSCHRIJVINGEN, true);
      foreach ($inschrijvingen as $cursus_id => $inschrijving) {
        if ((array_key_exists ($cursus_id, $cursussen)) AND (($inschrijving['i_betaald'] == 0) || ($inschrijving['c_betaald'] == 0))) {
          $html .= '<tr><td>' . $inschrijving['datum'] . '</td><td>' . $inschrijving['code'] . '</td><td>' . $inschrijver->display_name . '</td>
            <td><input type="checkbox" name="kleistad_i_betaald[]" value="' . $inschrijver->ID . ' ' . $cursus_id . '"' . ($inschrijving['i_betaald'] ? ' checked' : '') . ' ></td>
            <td><input type="checkbox" name="kleistad_c_betaald[]" value="' . $inschrijver->ID . ' ' . $cursus_id . '"' . ($inschrijving['c_betaald'] ? ' checked' : '') . ' ></td></tr>';
        }
      }
    }
    $html .= '</table>
      <button type="submit" name="kleistad_registreer_betalingen" >Opslaan</button>
      </form>';
    return $html;
  }

  /**
   * shortcode handler voor het uitlijsten van contactinformatie van geregistreerde cursisten.
   * 
   */
  public function registratie_overzicht_handler() {
    if (!$this->override()) {
      return '';
    }
    $this->enqueue_scripts();
    $this->setlocale_NL();

    $html = '';
    
    global $wpdb;
    $cursussen = $wpdb->get_results("SELECT id, naam FROM {$wpdb->prefix}kleistad_cursussen ORDER BY id", OBJECT_K);

    if (!is_null(filter_input(INPUT_POST, 'kleistad_registratiebestand_verzenden'))) {
      if (wp_verify_nonce(filter_input(INPUT_POST, '_wpnonce'), 'kleistad_registratie_bestand')) {

        $selectie = filter_input(INPUT_POST, 'selectie', FILTER_SANITIZE_STRING);
                
        $upload_dir = wp_upload_dir();
        $bijlage = $upload_dir['basedir'] . '/registratiebestand_' . date('Y_m_d') . '.csv';
        $f = fopen($bijlage, 'w');

        $fields = ['Achternaam', 'Voornaam', 'Email', 'Straat', 'Huisnr', 'Postcode', 'Plaats', 'Telefoon', 'Lid', 'Cursus', 'Cursus code', 'Inschrijf datum', 'Inschrijf status', 'Technieken', 'Opmerking'];
        fputcsv($f, $fields, ';', '"');

        $registraties = get_users(['orderby' => ['last_name']]); 
        
        foreach ($registraties as $registratie) {
          $is_lid = (!empty($registratie->roles) or (is_array($registratie->roles) and (count($registratie->roles) > 0 ) ) );
          if ( $selectie == '0' AND !$is_lid) {
            continue; // als er alleen leden geselecteerd zijn, de niet-leden overslaan
          } 
          
          $values = [$registratie->last_name, 
              $registratie->first_name, 
              $registratie->user_email];
          $contactinfo = get_user_meta($registratie->ID, self::CONTACTINFO, true);
          if (is_array($contactinfo)) {
            array_push ($values, 
              $contactinfo['straat'], 
              $contactinfo['huisnr'],
              $contactinfo['pcode'],
              $contactinfo['plaats'],
              $contactinfo['telnr']);
          } else {
            array_push ($values, '', '', '', '', '');
          }
          array_push ($values, $is_lid ? 'Ja' : 'Nee');
          
          $inschrijvingen = get_user_meta($registratie->ID, self::INSCHRIJVINGEN, true);
          if (is_array($inschrijvingen)) {
            foreach ($inschrijvingen as $cursus_id => $inschrijving) {
              if (intval($selectie) > 0 AND intval($selectie) != $cursus_id) {
                continue; // als er een cursus selectie is, alleen die cursus opnemen 
              } 
              $values_2 = $values;
              array_push ($values_2, $cursussen[$cursus_id]->naam,
                  $inschrijving['code'],
                  $inschrijving['datum'],
                  $inschrijving['ingedeeld'] ? 'ingedeeld' : 'wachtlijst',
                  is_array($inschrijving['technieken']) ? implode(' ', $inschrijving['technieken']) : '',
                  $inschrijving['opmerking']
              );
              fputcsv($f, $values_2, ';', '"');
            }
          } else {
            if (intval($selectie) > 0) {
              continue; // als er een cursus selectie is, dan leden zonder inschrijvingen overslaan
            }
            fputcsv($f, $values, ';', '"');
          }
        }
        fclose($f);

        $gebruiker = wp_get_current_user();
        $to = "$gebruiker->user_firstname $gebruiker->user_lastname <$gebruiker->user_email>";
        if ($selectie == '*') { 
          $message = "<p>Bijgaand het bestand in .CSV formaat met alle registraties.</p>";
        } elseif (intval($selectie) > 0) {
          $message = "<p>Bijgaand het bestand in .CSV formaat met alle registraties voor cursus C$selectie.</p>";
        } else {
          $message = "<p>Bijgaand het bestand in .CSV formaat met alle registraties van leden.</p>";
        }
        $attachments = [$bijlage];
        if ($this->compose_email($to, "Kleistad registratiebestand", $message, [], $attachments)) {
          $html .= '<div class="kleistad_succes"><p>Het bestand is per email verzonden.</p></div>';
        } else {
          $html .= '<div class="kleistad_fout"><p>Er is een fout opgetreden</p></div>';
        }
      }
    }

    $html .= '<div id="kleistad_deelnemer_info">
            <table class="kleistad_tabel" id="kleistad_deelnemer_tabel" ></table></div>
            <form action="#" method="post" >' . $this->nonce_field('kleistad_registratie_bestand') .
            '<p><label for="kleistad_deelnemer_selectie">Selectie</label><select id="kleistad_deelnemer_selectie" name="selectie" ><option value="*" ></option><option value="0" >Leden</option>';
    foreach ($cursussen as $cursus) {
      $html .= '<option value="' . $cursus->id . '">C' . $cursus->id . ' ' . $cursus->naam . '</option>';
    }
    
    $html .= '</select></p>
            <table class="kleistad_tabel" id="kleistad_deelnemer_lijst">
            <thead><tr><th>Achternaam</th><th>Voornaam</th><th>Straat</th><th>Huisnr</th><th>Postcode</th><th>Plaats</th><th>Email</th><th>Telnr</th></tr></thead><tbody>';
    $registraties = get_users(['orderby' => ['last_name']]);
    foreach ($registraties as $registratie) {
      $html .= '<tr class="kleistad_deelnemer_info" ';

      $contactinfo = get_user_meta($registratie->ID, self::CONTACTINFO, true);
      if (!is_array($contactinfo)) {
        $contactinfo = ['straat' => '', 'huisnr' => '', 'pcode' => '', 'plaats' => '', 'telnr' => ''];
      }

      $deelnemer = ['is_lid' => (!empty($registratie->roles) or (is_array($registratie->roles) and (count($registratie->roles) > 0 ) ) ), 
                    'naam' => $registratie->display_name, ];
      
      $inschrijvingen = get_user_meta($registratie->ID, self::INSCHRIJVINGEN, true);
      if (is_array($inschrijvingen)) {
        foreach ($inschrijvingen as $cursus_id => &$inschrijving) {
          $inschrijving['naam'] = $cursussen[$cursus_id]->naam;
        }
        $html .= 'data-inschrijvingen=\'' . json_encode($inschrijvingen). '\'';
      }
      $html .= ' data-deelnemer=\'' . json_encode($deelnemer) . '\' ><td>' . $registratie->last_name . '</td><td>' .
              $registratie->first_name . '</td><td>' .
              $contactinfo['straat'] . '</td><td>' .
              $contactinfo['huisnr'] . '</td><td>' .
              $contactinfo['pcode'] . '</td><td>' .
              $contactinfo['plaats'] . '</td><td>' .
              $registratie->user_email . '</td><td>' .
              $contactinfo['telnr'] . '</td></tr>';
    }
    $html .= '</tbody></table>
          <button type="submit" name="kleistad_registratiebestand_verzenden" >Bestand aanmaken</button>
        </form>';
    return $html;
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
        $stookdelen = [['id' => $transactie->gebruiker_id, 'perc' => 100],
            ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0], ['id' => 0, 'perc' => 0],];
      } else {
        $stookdelen = json_decode($transactie->verdeling, true);
      }
      $gebruiker = get_userdata($transactie->gebruiker_id);
      foreach ($stookdelen as $stookdeel) {
        if (intval($stookdeel['id']) == 0) {
          continue;
        }
        $medestoker = get_userdata($stookdeel['id']);

        $regeling = $this->lees_regeling($stookdeel['id'], $transactie->oven_id);
        $kosten = ( $regeling < 0 ) ? $transactie->kosten : $regeling;
        $prijs = round($stookdeel['perc'] / 100 * $kosten, 2);

        $huidig = (float) get_user_meta($stookdeel['id'], 'stooksaldo', true);
        $nieuw = ($huidig == '') ? 0 - (float) $prijs : round((float) $huidig - (float) $prijs, 2);

        $this->log_saldo("wijziging saldo $medestoker->display_name van $huidig naar $nieuw, stook op $datum.");
        update_user_meta($stookdeel['id'], 'stooksaldo', $nieuw);
        $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['verwerkt' => true], ['id' => $transactie->id], ['%d'], ['%d']);

        $bedrag = number_format($prijs, 2, ',', '');
        $saldo = number_format($nieuw, 2, ',', '');

        $to = "$medestoker->first_name $medestoker->last_name <$medestoker->user_email>";
        $this->compose_email($to, 'Kleistad kosten zijn verwerkt op het stooksaldo', 'kleistad_email_stookkosten_verwerkt', [
            'voornaam' => $medestoker->first_name, 
            'achternaam' => $medestoker->last_name,
            'stoker' => $gebruiker->display_name,
            'bedrag' => $bedrag,
            'saldo' => $saldo,
            'stookdeel' => $stookdeel['perc'],
            'stookdatum' => $datum,
            'stookoven' => $transactie->naam,
        ]);
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
      $datum_gebruik = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag, $notificatie->jaar));
      $datum_verwerking = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag + self::TERMIJN, $notificatie->jaar));
      $datum_deadline = strftime('%d-%m-%Y', mktime(0, 0, 0, $notificatie->maand, $notificatie->dag + self::TERMIJN - 1, $notificatie->jaar));
      $gebruiker = get_userdata($notificatie->gebruiker_id);

      // als er een speciale regeling / tarief is afgesproken, dan geldt dat tarief
      $regeling = $this->lees_regeling($notificatie->gebruiker_id, $notificatie->oven_id);
      $kosten = number_format(( $regeling < 0 ) ? $notificatie->kosten : $regeling, 2, ',', '');

      $wpdb->update("{$wpdb->prefix}kleistad_reserveringen", ['gemeld' => 1], ['id' => $notificatie->id], ['%d'], ['%d']);

      $to = "$gebruiker->first_name $gebruiker->last_name <$gebruiker->user_email>";
      $this->compose_email($to, "Kleistad oven gebruik op $datum_gebruik", 'kleistad_email_stookmelding', [
        'voornaam' => $gebruiker->first_name,
        'achternaam' => $gebruiker->last_name,
        'bedrag' => $kosten,
        'datum_verwerking' => $datum_verwerking,
        'datum_deadline' => $datum_deadline,
        'stookoven' => $notificatie->naam,
      ]);
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
    $oven_id = intval($request->get_param('oven_id'));
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
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven_id=\"$oven_id\" data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th style=\"text-align:right\"><button type=\"button\" class=\"kleistad_periode\" data-oven_id=\"$oven_id\" data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
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

    $reserveringen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}kleistad_reserveringen WHERE maand='$maand' AND jaar='$jaar' AND oven_id='$oven_id'");

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
          $kleur = 'white';
          $soortstook = '';
          $temperatuur = '';
          $programma = '';
          $verdeling = [['id' => $huidige_gebruiker_id, 'perc' => 100],
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
                $kleur = !$datum_verstreken ? 'lightgreen' : $kleur;
                $wijzigbaar = !$verwerkt || is_super_admin();
                $verwijderbaar = $this->override() ? !$verwerkt : !$datum_verstreken;
              } else {
                $kleur = !$datum_verstreken ? 'pink' : $kleur;
                // als de huidige gebruiker geen bevoegdheid heeft, dan geen actie
                $wijzigbaar = (!$verwerkt && $this->override()) || is_super_admin();
                $verwijderbaar = !$verwerkt && $this->override();
                $gebruiker_id = $reservering->gebruiker_id;
              }
              break; // exit de foreach loop
            }
          }
          $html .= "<tr style=\"background-color: $kleur\">";
          if ($wijzigbaar) {
            $form_data = [
                'oven_id' => $oven_id,
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
            $html .= "<td><a class=\"kleistad_box\" data-form='" . json_encode($form_data) . "' >$dagteller $dagnamen[$weekdag]</a></td>";
          } else {
            $html .= "<td>$dagteller $dagnamen[$weekdag]</td>";
          }
          $html .= "<td>$wie</td>
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
                    <th><button type=\"button\" class=\"kleistad_periode\" data-oven_id=\"$oven_id\" data-maand=\"$vorige_maand\" data-jaar=\"$vorige_maand_jaar\" >eerder</button></th>
                    <th colspan=\"3\"><strong>" . $maandnaam[$maand] . "-$jaar</strong></th>
                    <th style=\"text-align:right\"><button type=\"button\" class=\"kleistad_periode\" data-oven_id=\"$oven_id\" data-maand=\"$volgende_maand\" data-jaar=\"$volgende_maand_jaar\" >later</button></th>
                </tr>
            </tfoot>";
    return new WP_REST_response(['html' => $html, 'id' => $oven_id]);
  }

  /**
   * Callback handler (wordt vanuit browser aangeroepen) voor het wijzigen van de reserveringen
   * @global type $wpdb
   * @param WP_REST_Request $request
   * @return type
   */
  public function callback_muteren(WP_REST_Request $request) {
    global $wpdb;
//    error_log(print_r($request,true));
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
