<?php
/* Copyright (C) 2024 Votre Société — Licence GNU GPL v3 */

/**
 * PowrConnectScraper
 *
 * Authentification sur powr-connect.shop + extraction des prix via cURL/DOM.
 * Vérifier les CGU avant usage. Respecter le délai entre requêtes.
 */
class PowrConnectScraper
{
	private $baseUrl      = 'https://powr-connect.shop';
	private $cookieFile   = '';
	private $ch           = null;
	private $loggedIn     = false;
	private $requestDelay = 800000; // µs entre requêtes (0.8 s)
	private $userAgent    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

	public $error = '';

	/**
	 * Émet un message de debug via dol_syslog si disponible, sinon error_log
	 */
	private function debug($msg)
	{
		if (function_exists('dol_syslog')) {
			dol_syslog('[PowrConnectScraper] '.$msg, LOG_DEBUG);
		} else {
			error_log('[PowrConnectScraper] '.$msg);
		}
	}

	public function __construct($tempDir = '/tmp')
	{
		$this->cookieFile = $tempDir.'/powrsync_'.md5(__FILE__).'.txt';
	}

	private function initCurl()
	{
		if ($this->ch) {
			curl_close($this->ch);
		}
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_COOKIEJAR      => $this->cookieFile,
			CURLOPT_COOKIEFILE     => $this->cookieFile,
			CURLOPT_USERAGENT      => $this->userAgent,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => true,
		));
	}

	/**
	 * Authentification — POST les identifiants sur /connexion
	 * Retourne 1 si succès, -1 si échec
	 */
	public function login($email, $password)
	{
		$this->debug('login() — début, user='.$email);
		$this->initCurl();

		// Étape 1 : charger la page connexion pour récupérer le token CSRF
		$loginUrl = $this->baseUrl.'/connexion';
		$this->debug('GET '.$loginUrl);
		curl_setopt($this->ch, CURLOPT_URL, $loginUrl);
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		$html = curl_exec($this->ch);

		if (curl_errno($this->ch)) {
			$this->error = 'Connexion impossible : '.curl_error($this->ch);
			$this->debug('ERREUR cURL GET /connexion : '.$this->error);
			return -1;
		}
		$this->debug('GET /connexion OK, HTML length='.strlen($html).' bytes');

		$csrfToken = $this->extractCsrfToken($html);
		$this->debug('CSRF token extrait : '.($csrfToken ? substr($csrfToken, 0, 20).'…' : '(vide — non trouvé)'));

		// Étape 2 : soumettre le formulaire
		usleep($this->requestDelay);
		$this->debug('POST /connexion avec username='.$email.', csrf='.($csrfToken ? 'oui' : 'non'));
		curl_setopt_array($this->ch, array(
			CURLOPT_URL        => $loginUrl,
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'username'  => $email,
				'password'  => $password,
				'csrf'      => $csrfToken,
				'_action'   => 'login',
			)),
		));

		$postHtml = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$finalUrl = curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
		$this->debug('POST /connexion → HTTP '.$httpCode.' — URL finale : '.$finalUrl);

		if (curl_errno($this->ch)) {
			$this->error = 'Erreur cURL login : '.curl_error($this->ch);
			$this->debug('ERREUR cURL POST : '.$this->error);
			return -1;
		}

		// Login réussi = redirigé hors de /connexion, ou HTTP 302/303
		if (strpos($finalUrl, '/connexion') !== false && $httpCode >= 400) {
			$this->error = 'Identifiants incorrects ou structure de login modifiée (HTTP '.$httpCode.')';
			$this->debug('Échec login : toujours sur /connexion avec HTTP '.$httpCode);
			// Extrait un éventuel message d'erreur HTML pour aider au debug
			if (preg_match('/<[^>]*(?:alert|error|danger)[^>]*>([^<]{5,200})</i', $postHtml, $errMatch)) {
				$this->debug('Message erreur page : '.trim($errMatch[1]));
			}
			return -1;
		}

		$this->loggedIn = true;
		$this->debug('Login réussi — session active');
		return 1;
	}

	/**
	 * Récupère le prix HT d'un produit via son URL directe sur le site fournisseur
	 *
	 * @param  string $powrRef  Référence fournisseur (pour les messages d'erreur)
	 * @param  string $url      URL complète de la fiche produit sur le site fournisseur
	 * @return float|false      Prix HT ou false si non trouvé
	 */
	public function getPrice($powrRef, $url = '')
	{
		$this->debug('getPrice() — ref='.$powrRef.', url='.$url);

		if (!$this->loggedIn) {
			$this->error = 'Non authentifié';
			$this->debug('ERREUR : non authentifié');
			return false;
		}

		if (empty($url)) {
			$this->error = 'URL produit non renseignée pour '.$powrRef;
			$this->debug('ERREUR : URL manquante');
			return false;
		}

		usleep($this->requestDelay);

		$this->debug('GET '.$url);
		curl_setopt_array($this->ch, array(
			CURLOPT_URL     => $url,
			CURLOPT_HTTPGET => true,
		));

		$html     = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$finalUrl = curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
		$this->debug('GET produit → HTTP '.$httpCode.' — URL finale : '.$finalUrl.' — HTML length='.strlen($html).' bytes');

		if (curl_errno($this->ch)) {
			$this->error = 'cURL erreur ref '.$powrRef.' : '.curl_error($this->ch);
			$this->debug('ERREUR cURL : '.$this->error);
			return false;
		}
		if ($httpCode === 404) {
			$this->error = 'Référence '.$powrRef.' introuvable (404)';
			$this->debug('ERREUR 404 pour '.$powrRef);
			return false;
		}
		if ($httpCode === 302 || strpos($finalUrl, '/connexion') !== false) {
			$this->error = 'Session expirée ou non connecté — redirigé vers '.$finalUrl;
			$this->debug('ERREUR : redirection vers /connexion, session perdue');
			return false;
		}

		return $this->parsePrice($html, $powrRef);
	}

	/**
	 * Extrait le token CSRF de la page connexion
	 * Cherche un input hidden name="csrf"
	 */
	private function extractCsrfToken($html)
	{
		// Format : <input type="hidden" name="csrf" value="TOKEN">
		if (preg_match('/name=["\']csrf["\'][^>]+value=["\']([^"\']+)["\']/', $html, $m)) {
			$this->debug('CSRF trouvé (format name→value)');
			return $m[1];
		}
		// Format inversé : value="TOKEN" ... name="csrf"
		if (preg_match('/value=["\']([^"\']+)["\'][^>]+name=["\']csrf["\']/', $html, $m)) {
			$this->debug('CSRF trouvé (format value→name)');
			return $m[1];
		}
		$this->debug('CSRF non trouvé dans le HTML — envoi sans token');
		return '';
	}

	/**
	 * Parse le prix HT dans le HTML de la fiche produit POwR Connect
	 *
	 * Le prix se trouve dans un bloc avec la classe "font-semibold leading-none"
	 * à l'intérieur d'un conteneur "bg-secondary-100" (promo) ou "bg-grey-50" (normal).
	 * Format typique : <p class="text-[15px] font-semibold leading-none">661,38&nbsp;€</p>
	 */
	private function parsePrice($html, $powrRef)
	{
		$this->debug('parsePrice() — ref='.$powrRef.', HTML length='.strlen($html).' bytes');

		// Stratégie 1 : regex directe sur le pattern de prix affiché
		// Cherche le prix dans le bloc "À l'unité" (premier prix font-semibold leading-none suivi de €)
		$this->debug('Stratégie 1 : regex font-semibold leading-none + €');
		if (preg_match_all('/font-semibold leading-none["\'][^>]*>([0-9\s\xc2\xa0.,]+)\s*(?:&nbsp;)?€/u', $html, $matches)) {
			$this->debug('Regex S1 : '.count($matches[1]).' candidat(s) trouvé(s) : '.implode(' | ', $matches[1]));
			foreach ($matches[1] as $rawPrice) {
				$price = $this->cleanPrice($rawPrice.'€');
				$this->debug('  → cleanPrice("'.trim($rawPrice).'") = '.var_export($price, true));
				if ($price !== false && $price > 0) {
					$this->debug('Stratégie 1 réussie : prix='.$price);
					return $price;
				}
			}
		} else {
			$this->debug('Stratégie 1 : aucun match (classe CSS absente ou HTML différent)');
		}

		// Stratégie 2 : DOM/XPath fallback
		$this->debug('Stratégie 2 : DOM/XPath sur font-semibold + leading-none');
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);

		// Cherche les éléments avec font-semibold et leading-none qui contiennent un prix
		$priceNodes = $xpath->query('//*[contains(@class,"font-semibold") and contains(@class,"leading-none")]');
		$nodeCount = $priceNodes ? $priceNodes->length : 0;
		$this->debug('XPath S2 : '.$nodeCount.' nœud(s) trouvé(s)');
		if ($priceNodes) {
			foreach ($priceNodes as $node) {
				$text = trim($node->textContent);
				$this->debug('  nœud texte="'.$text.'"');
				if (preg_match('/[0-9]/', $text) && mb_strpos($text, '€') !== false) {
					$price = $this->cleanPrice($text);
					$this->debug('  → cleanPrice = '.var_export($price, true));
					if ($price !== false && $price > 0) {
						$this->debug('Stratégie 2 réussie : prix='.$price);
						return $price;
					}
				}
			}
		}

		// Stratégie 3 : JSON-LD schema.org (si le prix y est ajouté un jour)
		$this->debug('Stratégie 3 : JSON-LD schema.org');
		foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
			$json = json_decode(trim($script->textContent), true);
			$this->debug('  JSON-LD type='.($json['@type'] ?? '?').' — offers.price='.($json['offers']['price'] ?? 'absent'));
			if (!empty($json['offers']['price'])) {
				$price = (float) $json['offers']['price'];
				$this->debug('Stratégie 3 réussie : prix='.$price);
				return $price;
			}
		}

		$this->error = 'Prix non trouvé pour '.$powrRef.' — vérifier la structure HTML de la page';
		$this->debug('ERREUR : '.$this->error);
		return false;
	}

	/**
	 * Nettoie une chaîne prix (ex: "1 234,56 € HT" → 1234.56)
	 */
	private function cleanPrice($raw)
	{
		$v = preg_replace('/[€\s]|HT|TTC/ui', '', $raw);
		$v = str_replace(array("\xc2\xa0", ' '), '', $v); // espace insécable
		$v = str_replace(',', '.', $v);
		$v = preg_replace('/[^0-9.]/', '', $v);
		return is_numeric($v) ? (float) $v : false;
	}

	public function close()
	{
		if ($this->ch) {
			curl_close($this->ch);
			$this->ch = null;
		}
		$this->loggedIn = false;
	}

	public function __destruct()
	{
		$this->close();
	}
}
