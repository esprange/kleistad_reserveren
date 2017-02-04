=== Kleistad Reserveren ===
Contributors: E.Sprangers
Donate link: 
Tags: 
Requires at least: 4.x
Tested up to: 4.7.2
Stable tag: 
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


== Description ==
Plug-in voor reserveren van equipment (ovens) en voor het aanvragen en beheren van cursussen. Ovens kunnen op maandag, woensdag en vrijdag gereserveerd worden. Toegang op pagina via shortcode.

De volgende functies staan beschikbaar via shortcode voor leden:

•	[kleistad oven = X ], het bestaande overzicht van reservering voor oven nummer X. Let op, de naam van de oven wordt nu voortaan uit de database gelezen i.p.v. dat het een parameter (‘naam’) in de shortcode is.
•	[kleistad_saldo], een formulier voor de gebruiker waarmee deze kan aangeven dat er een storting per bank of kas is gedaan t.b.v. het stooksaldo
•	[kleistad_rapport], een rapport dat aan de gebruiker toont bij welke stookbeurten er kosten in rekening gebracht zijn / worden.
•	[kleistad_cursus_inschrijving], een formulier waarmee de gebruiker kan inschrijven op een cursus.


En voor bestuur of beheerders:
•	[kleistad_saldo_overzicht], een rapport dat het stooksaldo toont van alle gebruikers, voor zover bekend in het systeem. 
•	[kleistad_stookbestand], een formulier waarmee je een CSV bestand via de email kan aanvragen
•	[kleistad_ovens], een formulier waarmee je ovens kan toevoegen. Je gebruikt dit ook als er een nieuw tarief voor de ovens gaat gelden.  
•	[kleistad_regeling], een formulier waarmee je uitzonderingen in tarieven per gebruiker / oven kan aangeven 
•	[kleistad_cursus_beheer], een overzicht van actieve cursussen en popup formulieren voor beheer cursus info en indelen cursisten 
•	[kleistad_betalingen], een overzicht / formulier waarmee inschrijfgeld en cursusgeld van cursisten geregistreerd kan worden 
•	[kleistad_registratie_overzicht], een overzicht van alle contact informatie van cursisten en leden, voor zover bekend 

Leden kunnen reserveren, de eigen reservering wijzigen en eventueel verwijderen. 
Bestuur en beheerders kunnen reserveringen van iedereen wijzigen of verwijderen.

== Installation ==
Normale installatie.Installeren vanuit zip en daarna activeren.

== Changelog ==

= 1.0 =
Eerste versie welke gebruikt maakt van AJAX

= 1.1 =
Code optimalisatie van de kleistad class en verbetering styling.

= 2.0 =
Extra functionaliteiten (saldo beheer, rapporten, bestuur/beheerders functies) en meer gegevens vastleggen per reservering