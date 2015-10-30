<?php
/**
 * Part of the ETD Framework Fetcher Package
 *
 * @copyright   Copyright (C) 2015 ETD Solutions. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Fetcher;

use Joomla\Language\Text;
use Joomla\String\StringHelper;
use Joomla\Uri\UriHelper;

use Sunra\PhpSimple\HtmlDomParser;

class Fetcher {

    /**
     * @var Text L'outil de traduction.
     */
    protected $text;

    /**
     * @var array Les extensions valides des images.
     */
    protected $image_extensions = ['png', 'jpg', 'jpeg', 'gif'];

    /**
     * @var array Un tableau des méta-données à récupérer dans l'ordre d'importance. (du - au +)
     */
    protected $meta = [
        [
            'name' => 'description',
            'key'  => 'name',
            'tag'  => 'description'
        ],
        [
            'name' => 'description',
            'key'  => 'property',
            'tag'  => 'og:description'
        ],
        [
            'name' => 'description',
            'key'  => 'property',
            'tag'  => 'pinterestapp:about'
        ],
        [
            'name' => 'image',
            'key'  => 'property',
            'tag'  => 'og:image'
        ],
        [
            'name' => 'image',
            'key'  => 'itemprop',
            'tag'  => 'image'
        ],
        [
            'name' => 'title',
            'key'  => 'property',
            'tag'  => 'og:title'
        ],
        [
            'name' => 'video',
            'key'  => 'property',
            'tag'  => 'og:video'
        ],
        [
            'name' => 'video_type',
            'key'  => 'property',
            'tag'  => 'og:video:type'
        ],
        [
            'name' => 'video_width',
            'key'  => 'property',
            'tag'  => 'og:video:width'
        ],
        [
            'name' => 'video_height',
            'key'  => 'property',
            'tag'  => 'og:video:height'
        ]
    ];

    /**
     * @param Text $text L'outil de traduction.
     */
    function __construct(Text $text) {

        $this->text = $text;
    }

    /**
     * Méthode pour récupérer les informations d'une page par son URL.
     *
     * @param string $url L'adresse complète de la page.
     *
     * @return array Un tableau contenant les informations.
     *
     * @throws \InvalidArgumentException Si l'adresse est mal formatée.
     * @throws \RuntimeException         Si la page ne peut pas être traitée.
     */
    public function fetch($url) {

        // On contrôle que c'est bien une URL.
        if (!$this->testUrl($url)) {
            throw new \InvalidArgumentException($this->text->sprintf('APP_ERROR_BAD_URL', $url));
        }

        // On récupère le contenu de l'adresse.
        $dom = @HtmlDomParser::file_get_html($url);

        // Si une erreur est survenue.
        if (empty($dom)) {
            throw new \RuntimeException($this->text->sprintf('APP_ERROR_UNABLE_TO_LOAD_URL', $url));
        }

        // On récupère le titre de la page.
        $page_title = $dom->find('title')[0]->text();

        // On récupère les balises meta.
        $metas = [];
        foreach ($dom->find('head')[0]->find('meta') as $element) {
            foreach ($this->meta as $meta) {
                if ($element->hasAttribute($meta['key'])) {
                    if (strtolower($element->getAttribute($meta['key'])) == $meta['tag']) {
                        $content = $element->getAttribute('content');
                        if (!empty($content)) {
                            $metas[$meta['name']] = $content;
                        }
                    }

                }
            }
        }

        // On récupère le contenu de la page.
        $body = trim($dom->find('body')[0]->plaintext);
        $body = preg_replace('/\s+/', ' ', $body);
        $pos  = strpos($body, ' ', 200);
        $body = substr($body, 0, $pos);

        // On récupère les images.
        $images = [];
        foreach ($dom->find('img') as $element) {

            // On teste que c'est bien une URL valide.
            if ($this->testUrl($element->src)) {

                // On ne prend que les extensions images.
                $parts = UriHelper::parse_url($element->src);
                if (in_array($this->file_ext($parts['path']), $this->image_extensions)) {
                    $images[] = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
                }
            }
        }

        // Si on arrive ici c'est que tout s'est bien passé.
        return [
            'title'  => $page_title,
            'text'   => $body,
            'images' => $images,
            'metas'  => $metas
        ];

    }

    /**
     * Teste la validité d'une URL.
     *
     * @param string $value L'URL à tester.
     *
     * @return bool True si valide, false sinon.
     */
    protected function testUrl($value) {

        $urlParts = UriHelper::parse_url($value);

        // Protocoles autorisés.
        $scheme = array(
            'http',
            'https'
        );

        /*
         * This rule is only for full URLs with schemes because parse_url does not parse
         * accurately without a scheme.
         * @see http://php.net/manual/en/function.parse-url.php
         */
        if ($urlParts && !array_key_exists('scheme', $urlParts)) {
            return false;
        }

        $urlScheme = (string)$urlParts['scheme'];
        $urlScheme = strtolower($urlScheme);

        if (in_array($urlScheme, $scheme) == false) {
            return false;
        }

        // For some schemes here must be two slashes.
        if (($urlScheme == 'http' || $urlScheme == 'https') && ((substr($value, strlen($urlScheme), 3)) !== '://')) {
            return false;
        }

        // The best we can do for the rest is make sure that the strings are valid UTF-8
        // and the port is an integer.
        if (array_key_exists('host', $urlParts) && !StringHelper::valid((string)$urlParts['host'])) {
            return false;
        }

        if (array_key_exists('port', $urlParts) && !is_int((int)$urlParts['port'])) {
            return false;
        }

        if (array_key_exists('path', $urlParts) && !StringHelper::valid((string)$urlParts['path'])) {
            return false;
        }

        return true;
    }

    /**
     * Extrait l'extension d'un fichier.
     *
     * @param string $filename Le nom de fichier
     *
     * @return string L'extension.
     */
    protected function file_ext($filename) {

        $path_parts = pathinfo($filename);

        return array_key_exists('extension', $path_parts) ? $path_parts['extension'] : '';
    }

}