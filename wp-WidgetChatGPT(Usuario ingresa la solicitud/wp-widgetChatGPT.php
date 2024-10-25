<?php
/*
Plugin Name: WP ChatGPT Respuestas en Widget
Description: Un widget para que los usuarios ingresen texto y reciban respuestas de ChatGPT.
Version: 1.1
Author: Leandro Diaz, Daniel Vargas, Sebastian Zuñiga
*/

// Función para manejar la solicitud del usuario y obtener la respuesta de ChatGPT
function handle_user_input_and_chatgpt_response() {
    if (isset($_POST['user_input'])) {
        $user_input = sanitize_text_field($_POST['user_input']);

        if (!empty($user_input)) {
            // Llamar a la API de ChatGPT con el input del usuario
            $api_url = 'https://api.openai.com/v1/chat/completions';
            $api_key = 'api key'; // Reemplaza con tu clave API

            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'model' => 'gpt-3.5-turbo',
                    'messages' => array(
                        array('role' => 'user', 'content' => $user_input)
                    ),
                    'max_tokens' => 60
                ))
            );

            $response = wp_remote_post($api_url, $args);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            global $wpdb;
            $table_name = $wpdb->prefix . 'motivational_quotes'; // Puedes renombrar esta tabla

            // Verificar si la respuesta de ChatGPT fue correcta
            if (!is_wp_error($response) && isset($result['choices'][0]['message']['content'])) {
                $chatgpt_response = sanitize_text_field($result['choices'][0]['message']['content']);
                $wpdb->insert($table_name, array(
                    'quote' => $chatgpt_response,
                    'created_at' => current_time('mysql')
                ));
                return $chatgpt_response;
            } else {
                // Si la API no responde o hay un error, almacenamos un mensaje de error
                $error_message = "No se recibió ninguna respuesta.";
                $wpdb->insert($table_name, array(
                    'quote' => sanitize_text_field($error_message),
                    'created_at' => current_time('mysql')
                ));
                return $error_message;
            }
        }
    }
    return null;
}

// Definir la clase del Widget interactivo
class ChatGPT_Interactive_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'chatgpt_interactive_widget',
            __('ChatGPT Interactivo', 'text_domain'),
            array('description' => __('Permite a los usuarios ingresar texto y obtener respuestas de ChatGPT en tiempo real.', 'text_domain'))
        );
    }

    public function widget($args, $instance) {
        echo $args['before_widget'];

        // Mostrar el formulario para que los usuarios ingresen su texto
        ?>
        <form method="POST">
            <label for="user_input">Escribe tu pregunta o texto:</label>
            <input type="text" name="user_input" id="user_input" required>
            <input type="submit" value="Enviar">
        </form>
        <?php

        // Procesar el texto ingresado por el usuario y mostrar la respuesta de ChatGPT
        $chatgpt_response = handle_user_input_and_chatgpt_response();
        if ($chatgpt_response) {
            echo '<p><strong>Respuesta de ChatGPT:</strong> ' . esc_html($chatgpt_response) . '</p>';
        }

        echo $args['after_widget'];
    }

    public function form($instance) {
        // Puedes agregar configuraciones si es necesario, por ahora está vacío
    }

    public function update($new_instance, $old_instance) {
        return $new_instance;
    }
}

// Registrar el Widget
function register_chatgpt_interactive_widget() {
    register_widget('ChatGPT_Interactive_Widget');
}
add_action('widgets_init', 'register_chatgpt_interactive_widget');

// Función para crear la tabla en la base de datos al activar el plugin
function create_chatgpt_responses_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'motivational_quotes'; // Cambia el nombre si es necesario

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id INT(11) NOT NULL AUTO_INCREMENT,
        quote TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Hook para crear la tabla al activar el plugin
register_activation_hook(__FILE__, 'create_chatgpt_responses_table');

global $wpdb;
$table_name = $wpdb->prefix . 'motivational_quotes'; // Cambia el nombre si es necesario
?>
