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
		$this->initCurl();

		// Étape 1 : charger la page connexion pour récupérer le token CSRF
		curl_setopt($this->ch, CURLOPT_URL, $this->baseUrl.'/connexion');
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		$html = curl_exec($this->ch);

		if (curl_errno($this->ch)) {
			$this->error = 'Connexion impossible : '.curl_error($this->ch);
			return -1;
		}

		$csrfToken = $this->extractCsrfToken($html);

		// Étape 2 : soumettre le formulaire
		usleep($this->requestDelay);
		curl_setopt_array($this->ch, array(
			CURLOPT_URL        => $this->baseUrl.'/connexion',
			CURLOPT_POST       => true,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'username'  => $email,
				'password'  => $password,
				'csrf'      => $csrfToken,
				'_action'   => 'login',
			)),
		));

		curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		$finalUrl = curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);

		if (curl_errno($this->ch)) {
			$this->error = 'Erreur cURL login : '.curl_error($this->ch);
			return -1;
		}

		// Login réussi = redirigé hors de /connexion, ou HTTP 302/303
		if (strpos($finalUrl, '/connexion') !== false && $httpCode >= 400) {
			$this->error = 'Identifiants incorrects ou structure de login modifiée (HTTP '.$httpCode.')';
			return -1;
		}

		$this->loggedIn = true;
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
		if (!$this->loggedIn) {
			$this->error = 'Non authentifié';
			return false;
		}

		if (empty($url)) {
			$this->error = 'URL produit non renseignée pour '.$powrRef;
			return false;
		}

		usleep($this->requestDelay);

		curl_setopt_array($this->ch, array(
			CURLOPT_URL  => $url,
			CURLOPT_HTTPGET => true,
		));

		$html     = curl_exec($this->ch);
		$httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

		if (curl_errno($this->ch)) {
			$this->error = 'cURL erreur ref '.$powrRef.' : '.curl_error($this->ch);
			return false;
		}
		if ($httpCode === 404) {
			$this->error = 'Référence '.$powrRef.' introuvable (404)';
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
			return $m[1];
		}
		// Format inversé : value="TOKEN" ... name="csrf"
		if (preg_match('/value=["\']([^"\']+)["\'][^>]+name=["\']csrf["\']/', $html, $m)) {
			return $m[1];
		}
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
		// Stratégie 1 : regex directe sur le pattern de prix affiché
		// Cherche le prix dans le bloc "À l'unité" (premier prix font-semibold leading-none suivi de €)
		if (preg_match_all('/font-semibold leading-none["\'][^>]*>([0-9\s\xc2\xa0.,]+)\s*(?:&nbsp;)?€/u', $html, $matches)) {
			foreach ($matches[1] as $rawPrice) {
				$price = $this->cleanPrice($rawPrice.'€');
				if ($price !== false && $price > 0) {
					return $price;
				}
			}
		}

		// Stratégie 2 : DOM/XPath fallback
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
		libxml_clear_errors();

		$xpath = new DOMXPath($dom);

		// Cherche les éléments avec font-semibold et leading-none qui contiennent un prix
		$priceNodes = $xpath->query('//*[contains(@class,"font-semibold") and contains(@class,"leading-none")]');
		if ($priceNodes) {
			foreach ($priceNodes as $node) {
				$text = trim($node->textContent);
				if (preg_match('/[0-9]/', $text) && mb_strpos($text, '€') !== false) {
					$price = $this->cleanPrice($text);
					if ($price !== false && $price > 0) {
						return $price;
					}
				}
			}
		}

		// Stratégie 3 : JSON-LD schema.org (si le prix y est ajouté un jour)
		foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
			$json = json_decode(trim($script->textContent), true);
			if (!empty($json['offers']['price'])) {
				return (float) $json['offers']['price'];
			}
		}

		$this->error = 'Prix non trouvé pour '.$powrRef.' — vérifier la structure HTML de la page';
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
