<?php

declare( strict_types=1 );

namespace Rtgroup\DevRouter\Core;



class Request
{
    /**
     * @var array Request data
     */
    private array $data;

    /**
     * Request constructor.
     */
    public function __construct() {
        $requestMethod = mb_strtoupper(( $_SERVER['REQUEST_METHOD'] ?? "" ));
        if ( isset($_SERVER['REQUEST_METHOD']) && in_array($requestMethod, [ Router::PATCH, Router::PUT ]) ) {
            $input = $this->parse_patch_and_put_request_data();
        } else {
            $rawInput = file_get_contents('php://input');



            $input = json_decode($rawInput) ?? [];
            if ( is_array($input) && count($input) == 0 ) {
                mb_parse_str($rawInput, $input);
            }
        }

        $_REQUEST = array_merge($_REQUEST, (array) $input);
        $this->data = $_REQUEST;
    }

    /**
     * Renvoie une valeur pour un argument de corps spécifié
     *
     * @param string $key Quel argument du corps de la requête doit être renvoyé
     *
     * @return mixed Valeur de l'argument du corps ou NULL si l'argument n'existe pas
     */
    public function get(string $key = '') : mixed {
        return $this->data[$key] ?? NULL;
    }

    /**
     * Renvoie tous les arguments de corps spécifiés
     * @return array|null
     */
    public function getAll(): ?array
    {
        return $this->data ?? null;
    }

    /**
     * Renvoie la liste de tous les éléments d'en-tête ou une valeur d'une clé spécifiée.
     * Il renverra NULL si la clé spécifiée est introuvable.
     *
     * @param string $key Nom d'un élément spécifique dans la liste d'en-tête pour lequel renvoyer la valeur
     *
     * @return array|string|null Liste de valeurs d'en-tête ou valeur d'un seul élément.
     */
    public function headers(string $key = '') : array|string|null {
        $headers = $this->get_request_headers();
        return empty($key) ? $headers : array_filter($headers, function ($k) use ($key
        ) {
            /**
             * Ceci afin de nous assurer que nous pouvons obtenir une correspondance sur une clé, car il n'est pas garanti que les clés le seront.
             * Soyez toujours au format majuscules/minuscules car certains clients/sdks ne respectent pas cette spécification.
             */
            return strtolower($k) === strtolower($key);
        }, ARRAY_FILTER_USE_KEY) ?? NULL;
    }

    /**
     * Méthode utilisée pour obtenir tous les en-têtes de requête.
     *
     * @return array Il renverra un tableau contenant toutes les valeurs d'en-tête ou un tableau vide
     */
    private function get_request_headers() : array {
        if ( function_exists("apache_request_headers") ) {
            $headers = apache_request_headers() ?? NULL;
            return $headers ?? [];
        }

        $headers = [];
        foreach ( $_SERVER as $key => $value ) {
            if ( str_starts_with("HTTP_", $key) ) {
                $k = str_replace("HTTP_", "", $key);
                $headers[$k] = $value;
            }
        }

        return $headers;
    }

    /**
     * Définit le code d'état d'en-tête pour la réponse
     *
     * @param int $statusCode Code d'état à définir pour la réponse
     * @param string $message Message à envoyer dans l'en-tête à côté du code d'état
     *
     * @return Request Renvoie une instance de la classe Request afin qu'elle puisse être chaînée sur
     *
     */
    public function status(int $statusCode = 200, string $message = '') : self {
        header("HTTP/1.1 $statusCode $message");
        return $this;
    }

    /**
     * Méthode utilisée pour définir les propriétés d'en-tête personnalisées
     *
     *
     * @param string|array|object $key Header key value
     * @param mixed $value Header value
     *
     * @return Request Renvoie une instance de la classe Request afin qu'elle puisse être chaînée sur
     *
     */
    public function header(string|array|object $key, mixed $value = NULL) : self {
        if ( is_string($key) ) {
            header("$key: $value");
        } elseif ( is_array($key) || is_object($key) ) {
            $keys = $key;
            foreach ( $keys as $key => $value ) {
                header("$key: $value");
            }
        }
        return $this;
    }

    /**
     * Send response back
     * @param string|array|object $output Valeur à afficher dans le cadre de la réponse
     * @param array|object|null $headers Liste facultative de propriétés d'en-tête personnalisées à envoyer avec la réponse
     */
    public function send(string|array|object $output, array|object|null $headers = NULL) : void {
        if ( !is_null($headers) ) {
            $this->header($headers);
        }
        echo json_encode($output);
    }

    /**
     * Méthode privée utilisée pour analyser les données du corps de la requête pour les requêtes PUT et PATCH
     *
     * @return array Renvoie un tableau de données du corps de la requête
     */
    private function parse_patch_and_put_request_data() : array {

        /* PUT data comes in on the stdin stream */
        $putData = fopen('php://input', 'r');

        $raw_data = '';

        /* Read the data 1 KB at a time and write to the file */
        while ( $chunk = fread($putData, 1024) )
            $raw_data .= $chunk;

        /* Close the streams */
        fclose($putData);

        // Fetch content and determine boundary
        $boundary = substr($raw_data, 0, strpos($raw_data, "\r\n"));

        if ( empty($boundary) ) {
            parse_str($raw_data, $data);
            return $data ?? [];
        }

        // Fetch each part
        $parts = array_slice(explode($boundary, $raw_data), 1);
        $data = [];

        foreach ( $parts as $part ) {
            // If this is the last part, break
            if ( $part == "--\r\n" ) break;

            // Separate content from headers
            $part = ltrim($part, "\r\n");
            [ $raw_headers, $body ] = explode("\r\n\r\n", $part, 2);

            // Parse the headers list
            $raw_headers = explode("\r\n", $raw_headers);
            $headers = [];
            foreach ( $raw_headers as $header ) {
                [ $name, $value ] = explode(':', $header);
                $headers[strtolower($name)] = ltrim($value, ' ');
            }

            // Parse the Content-Disposition to get the field name, etc.
            if ( isset($headers['content-disposition']) ) {
                preg_match(
                    '/^(.+); *name="([^"]+)"(; *filename="([^"]+)")?/',
                    $headers['content-disposition'],
                    $matches
                );
                [ , $type, $name ] = $matches;
                //Parse File
                if ( isset($matches[4]) ) {
                    //if labeled the same as previous, skip
                    if ( isset($_FILES[$matches[2]]) ) {
                        continue;
                    }

                    //get filename
                    $filename = $matches[4] ?? NULL;

                    //get tmp name
                    $filename_parts = pathinfo($filename);
                    $tmp_name = tempnam(ini_get('upload_tmp_dir'), $filename_parts['filename']);

                    //populate $_FILES with information, size may be off in multibyte situation
                    $_FILES[$matches[2]] = [
                        'error' => 0,
                        'name' => $filename,
                        'tmp_name' => $tmp_name,
                        'size' => strlen($body),
                        'type' => $type,
                    ];

                    //place in temporary directory
                    file_put_contents($tmp_name, $body);
                } else { //Parse Field
                    $data[$name] = substr($body, 0, strlen($body) - 2);
                }
            }
        }
        return $data ?? [];
    }
}