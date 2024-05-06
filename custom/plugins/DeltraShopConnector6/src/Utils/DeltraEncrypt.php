<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Utils;

use Exception;

class DeltraEncrypt
{
/**
	 * Initialization Vector.
	 * Wird als Shared Secret mit OMX/dem Shopconnector verwendet
	 * @var string
	 */
	private $IV = "jxpPjWLPIlLXWxrc";

	/**
	 * Verschlüsselt den per Parameter übergebenen String mit dem per Parameter
	 * übergebenen Schlüssel und vordefiniertem IV
	 * @param string $string 	'zu verschlüsselnder Kontext'
	 * @param string $key 		'Passwort, mit dem verschlüsselt wird'
	 * @return string 			'verschlüsselter String'
	 * @throws \Exception
	 */
	public function encryptString($string, $key)
	{
		$key = mb_convert_encoding($key, "ISO-8859-15", "UTF-8");
		if (extension_loaded('openssl'))
		{
			$zeroPaddedString = $this->zeroPadOpenSSL($string);
			$cipher = $this->getCipher($key);
			$encryptedData = openssl_encrypt($zeroPaddedString, $cipher, $key, OPENSSL_ZERO_PADDING, $this->IV);

			return $encryptedData;
		}
		else
		{
			throw new Exception("Module \"openssl\" not found");
		}
	}

	/**
	 * Zero-Padded die Daten für die openssl_encrypt Methode
	 * @param string $data	'Zu paddende Daten'
	 * @return string
	 */
	private function zeroPadOpenSSL($data)
	{
		$pad = 16;
		$pad = $pad - (strlen($data) % $pad);
		$data .= str_repeat("\0", $pad);

		return $data;
	}

	/**
	 * Ermittelt je nach länge des Keys den passenden
	 * Cipher, wie mcrypt dies getan hätte
	 * @param string $key	'Der Key der zur Verschlüsselung verwendet werden soll'
	 * @return string		'OpenSSL AES-CBC-Cipher'
	 */
	private function getCipher($key)
	{
		$CIPHER_SMALL = "aes-128-cbc";
		$CIPHER_LARGE = "aes-256-cbc";

		if (strlen($key) > 16)
			return $CIPHER_LARGE;

		return $CIPHER_SMALL;
	}
}