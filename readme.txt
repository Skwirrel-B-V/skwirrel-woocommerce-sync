=== Skwirrel PIM Sync ===
Contributors: skwirrel
Tags: woocommerce, sync, erp, pim, skwirrel
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchroniseert producten van het Skwirrel ERP/PIM systeem naar WooCommerce via een JSON-RPC 2.0 API.

== Description ==

Skwirrel PIM Sync koppelt je WooCommerce webshop aan het Skwirrel ERP/PIM systeem. Producten, variaties, afbeeldingen en documenten worden automatisch gesynchroniseerd.

**Mogelijkheden:**

* Volledige en delta synchronisatie van producten
* Ondersteuning voor eenvoudige en variabele producten
* Automatische import van productafbeeldingen en documenten
* Geplande synchronisatie via WP-Cron of Action Scheduler
* Handmatige synchronisatie vanuit het WordPress admin paneel
* ETIM classificatie ondersteuning voor variatie-assen

**Vereisten:**

* WooCommerce 8.0 of hoger
* PHP 8.1 of hoger
* Een actief Skwirrel account met API-toegang

== Installation ==

1. Upload de plugin bestanden naar `/wp-content/plugins/skwirrel-pim-wp-sync/`, of installeer de plugin direct via het WordPress plugin scherm.
2. Activeer de plugin via het 'Plugins' scherm in WordPress.
3. Ga naar WooCommerce → Skwirrel Sync om de plugin in te stellen.
4. Vul je Skwirrel API URL en authenticatie token in.
5. Klik op 'Sync nu' om de eerste synchronisatie te starten.

== Frequently Asked Questions ==

= Welke Skwirrel API versie wordt ondersteund? =

De plugin werkt met de Skwirrel JSON-RPC 2.0 API.

= Hoe vaak worden producten gesynchroniseerd? =

Je kunt een automatisch schema instellen (elk uur, tweemaal daags, of dagelijks) of handmatig synchroniseren vanuit de instellingenpagina.

= Worden bestaande producten overschreven? =

De plugin gebruikt het Skwirrel external ID als unieke sleutel. Bestaande producten worden bijgewerkt, niet gedupliceerd.

== Changelog ==

= 1.1.2 =
* Versie bump

= 1.0.0 =
* Eerste release
* Volledige product synchronisatie
* Variabele producten met ETIM variatie-assen
* Afbeelding en document import
* Delta synchronisatie ondersteuning
