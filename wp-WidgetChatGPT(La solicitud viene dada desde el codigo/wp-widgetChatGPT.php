<?php
/*
Plugin Name: WP Motivational Quotes
Description: Un plugin para mostrar frases motivacionales generadas por ChatGPT y almacenadas en la base de datos.
Version: 1.0
Author: Leandro Diaz, Daniel Vargas, Sebastian Zuñiga
*/

// Código del plugin empezará aquí.
// Código para el CRON que obtiene una frase de ChatGPT y la almacena en la base de datos
function get_chatgpt_motivational_quote() {
    $api_url = 'https://api.openai.com/v1/chat/completions';
    $api_key = 'api key'; // Asegúrate de usar tu API Key

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode(array(
            'model' => 'gpt-3.5-turbo', // O el modelo que estés usando
            'messages' => array(
                array('role' => 'user', 'content' => 'Dame una frase motivacional.')
            ),
            'max_tokens' => 60
        ))
    );

    $response = wp_remote_post($api_url, $args);
    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    global $wpdb;
    $table_name = $wpdb->prefix . 'motivational_quotes';

    // Verificar si la respuesta es correcta y contiene el mensaje
    if (!is_wp_error($response) && isset($result['choices'][0]['message']['content'])) {
        $wpdb->insert($table_name, array(
            'quote' => sanitize_text_field($result['choices'][0]['message']['content']),
            'created_at' => current_time('mysql')
        ));
    } else {
        // Si la API no responde o hay un error, almacenamos un mensaje en la base de datos
        $error_message = "No se recibió ninguna frase motivacional.";
        $wpdb->insert($table_name, array(
            'quote' => sanitize_text_field($error_message),
            'created_at' => current_time('mysql')
        ));
    }
}

// Función para agregar el intervalo de cron de 2 minutos
function add_two_minutes_interval($schedules) {
    $schedules['two_minutes'] = array(
        'interval' => 120,
        'display' => __('Cada 2 minutos')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_two_minutes_interval');

// Programar el CRON para que se ejecute cada 2 minutos
function activate_motivational_quote_plugin() {
    if (!wp_next_scheduled('generate_motivational_quote_event')) {
        wp_schedule_event(time(), 'two_minutes', 'generate_motivational_quote_event');
    }
}
register_activation_hook(__FILE__, 'activate_motivational_quote_plugin');

// Acción para el evento CRON
add_action('generate_motivational_quote_event', 'get_chatgpt_motivational_quote');

// Definir la clase del Widget
class Motivational_Quote_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'motivational_quote_widget',
            __('Frase Motivacional', 'text_domain'),
            array('description' => __('Muestra una frase motivacional generada por ChatGPT.', 'text_domain'))
        );
    }

    public function widget($args, $instance) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'motivational_quotes';

        $quote = $wpdb->get_var("SELECT quote FROM $table_name ORDER BY created_at DESC LIMIT 1");

        echo $args['before_widget'];
        if (!empty($quote)) {
            echo $args['before_title'] . apply_filters('widget_title', 'Frase Motivacional') . $args['after_title'];
            echo '<p>' . esc_html($quote) . '</p>';
        } else {
            echo '<p>No hay frases disponibles.</p>';
        }
        echo $args['after_widget'];
    }

    public function form($instance) {}

    public function update($new_instance, $old_instance) {
        return $new_instance;
    }
}

// Registrar el Widget
function register_motivational_quote_widget() {
    register_widget('Motivational_Quote_Widget');
}
add_action('widgets_init', 'register_motivational_quote_widget');

// Función para crear la tabla en la base de datos al activar el plugin
function create_motivational_quotes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'motivational_quotes';

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
register_activation_hook(__FILE__, 'create_motivational_quotes_table');

global $wpdb;
$table_name = $wpdb->prefix . 'motivational_quotes';
