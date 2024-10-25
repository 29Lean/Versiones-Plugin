<?php
ob_start(); // Inicia el búfer de salida

/*
Plugin Name: WP ChatGPT Custom Queries
Description: Un plugin para mostrar respuestas de ChatGPT basadas en consultas personalizadas del usuario y almacenadas en la base de datos.
Version: 1.7
Author: Equipo desarrollo
*/

// Crear las tablas al activar el plugin
function create_chatgpt_tables() {
    global $wpdb;

    // Crear tabla para consultas
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    $charset_collate = $wpdb->get_charset_collate();

    $sql_queries = "CREATE TABLE IF NOT EXISTS $queries_table (
        id INT(11) NOT NULL AUTO_INCREMENT,
        query_name VARCHAR(255) NOT NULL,
        query TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 0,
        mostrar_respuesta ENUM('ultima', 'aleatoria') DEFAULT 'ultima',
        model VARCHAR(255) DEFAULT 'gpt-3.5-turbo',  
        html_widget TEXT,
        PRIMARY KEY (id)
        
    ) $charset_collate;";

    // Crear tabla para respuestas
    $responses_table = $wpdb->prefix . 'chatgpt_responses';
    $sql_responses = "CREATE TABLE IF NOT EXISTS $responses_table (
        id INT(11) NOT NULL AUTO_INCREMENT,
        query_id INT(11) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        response TEXT NOT NULL,
        PRIMARY KEY (id),
        FOREIGN KEY (query_id) REFERENCES $queries_table(id) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_queries);
    dbDelta($sql_responses);
}

register_activation_hook(_FILE_, 'create_chatgpt_tables');


// Código para el CRON que obtiene una respuesta de ChatGPT y la almacena en la base de datos
function get_chatgpt_response($specific_query = null) {
    global $wpdb; 
    $responses_table = $wpdb->prefix . 'chatgpt_responses'; 
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    
    // Determinar qué consultas procesar
    if ($specific_query !== null) {
        // Si se proporciona una consulta específica, crear un array con solo esa consulta
        $queries = array($specific_query);
    } else {
        // Si no se proporciona consulta específica, obtener todas las consultas activas
        $queries = $wpdb->get_results("SELECT * FROM $queries_table WHERE is_active = 1");
    }
    
    foreach ($queries as $query) {
        $api_url = 'https://api.openai.com/v1/chat/completions'; 
        $api_key = get_option('chatgpt_api_key'); 

        $args = array( 
            'headers' => array( 
                'Authorization' => 'Bearer ' . $api_key, 
                'Content-Type' => 'application/json' 
            ), 
            'body' => json_encode(array( 
                'model' => $query->model,  
                'messages' => array( 
                    array('role' => 'user', 'content' => $query->query) 
                ), 
                'max_tokens' => 320 
            ))
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $wpdb->insert(
                $responses_table,
                array(
                    'query_id' => $query->id,
                    'response' => 'Error: ' . sanitize_text_field($error_message),
                    'created_at' => current_time('mysql')
                )
            );
            continue;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (isset($result['choices'][0]['message']['content'])) {
            $chatgpt_response = $result['choices'][0]['message']['content'];
            $wpdb->insert(
                $responses_table,
                array(
                    'query_id' => $query->id,
                    'response' => sanitize_text_field($result['choices'][0]['message']['content']),
                    'created_at' => current_time('mysql')
                )
            );
            
            $response_data = json_decode($chatgpt_response, true);
            if ($response_data && is_array($response_data)) {
                $placeholders = '';
                foreach ($response_data as $key => $value) {
                    $placeholders .= '{{' . $key . '}} ';
                }
                $html_widget = '<p>' . trim($placeholders) . '</p>';
        
                $wpdb->update(
                    $queries_table,
                    array('html_widget' => $html_widget),
                    array('id' => $query->id)
                );
            }
        }
    }
}

// Añadir el intervalo personalizado basado en la opción guardada
function add_custom_interval($schedules) {
    
    $cron_interval_hours = get_option('chatgpt_cron_interval', 2); // 2 horas por defecto
    $schedules['custom_interval'] = array(
        'interval' => $cron_interval_hours * 3600, // Convertir horas a segundos
        'display' => __('Cada ' . $cron_interval_hours . ' horas')
    );
    return $schedules;
}
add_filter('cron_schedules', 'add_custom_interval');


// Función para reprogramar el CRON con el nuevo intervalo
function reprogram_chatgpt_cron($cron_interval_hours) {
    if (wp_next_scheduled('generate_chatgpt_response_event')) {
        wp_clear_scheduled_hook('generate_chatgpt_response_event');
    }
    wp_schedule_event(time(), 'custom_interval', 'generate_chatgpt_response_event');
}

function activate_chatgpt_plugin() {
    if (!wp_next_scheduled('generate_chatgpt_response_event')) {
        wp_schedule_event(time(), 'custom_interval', 'generate_chatgpt_response_event');
    }
}
register_activation_hook(_FILE_, 'activate_chatgpt_plugin');


// Acción para el evento CRON
add_action('generate_chatgpt_response_event', 'get_chatgpt_response');

// Función para agregar el menú de configuración
function chatgpt_queries_menu() {
    add_options_page(
        'Configuración de Consultas ChatGPT', // Título de la página
        'Consultas ChatGPT', // Título del menú
        'manage_options', // Capacidad
        'chatgpt-queries', // Slug
        'chatgpt_queries_options_page' // Función que muestra la página
    );
}
add_action('admin_menu', 'chatgpt_queries_menu');

// Clase para crear el Widget de ChatGPT
class ChatGPT_Response_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'chatgpt_response_widget', // ID único del widget
            __('ChatGPT Response', 'text_domain'), // Nombre del widget
            array('description' => __('Muestra la respuesta de una consulta de ChatGPT.', 'text_domain')) // Descripción del widget
        );
    }

 // Función para mostrar el contenido del widget en el frontend
 public function widget($args, $instance) { 
    global $wpdb; 
    $queries_table = $wpdb->prefix . 'chatgpt_queries'; 
    $responses_table = $wpdb->prefix . 'chatgpt_responses'; 
    $selected_query_id = isset($instance['query_id']) ? intval($instance['query_id']) : 0; 

    if ($selected_query_id) { 
        // Obtener la consulta seleccionada 
        $query = $wpdb->get_row($wpdb->prepare("SELECT * FROM $queries_table WHERE id = %d", $selected_query_id)); 

        if ($query) { 
            // Manejar el cambio de la opción de mostrar_respuesta
            if (isset($_POST['mostrar_respuesta']) && isset($_POST['query_id'])) { 
                $query_id = intval($_POST['query_id']); 
                $mostrar_respuesta = sanitize_text_field($_POST['mostrar_respuesta']); 

                // Guardar la nueva opción en la base de datos
                $wpdb->update($queries_table, array('mostrar_respuesta' => $mostrar_respuesta), array('id' => $query_id)); 
                // Volver a obtener la consulta después de la actualización
                $query = $wpdb->get_row($wpdb->prepare("SELECT * FROM $queries_table WHERE id = %d", $query_id)); 
            }

            // Obtener la respuesta según la preferencia del usuario 
            if ($query->mostrar_respuesta == 'ultima') { 
                $response_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $responses_table WHERE query_id = %d ORDER BY created_at DESC LIMIT 1", $selected_query_id)); 
            } elseif ($query->mostrar_respuesta == 'aleatoria') { 
                $responses = $wpdb->get_results($wpdb->prepare("SELECT * FROM $responses_table WHERE query_id = %d", $selected_query_id)); 
                if (!empty($responses)) { 
                    $response_row = $responses[array_rand($responses)]; // Obtener una respuesta aleatoria 
                } else { 
                    $response_row = null; // No hay respuestas disponibles
                } 
            } 

            // Mostrar el contenido del widget 
            echo $args['before_widget']; 

            // Mostrar el título del widget
            echo $args['before_title'] . apply_filters('widget_title', esc_html($query->query_name)) . $args['after_title']; 

            if ($response_row) { 
                // Obtener el HTML del widget
                $html_template = $query->html_widget ? $query->html_widget : '<p>No hay contenido HTML disponible.</p>';

                // Decodificar la respuesta JSON
                $response_data = json_decode($response_row->response, true);

                if ($response_data && is_array($response_data)) {
                    // Reemplazar los placeholders con los valores reales
                    foreach ($response_data as $key => $value) {
                        $html_template = str_replace('{{' . $key . '}}', esc_html($value), $html_template);
                    }
                    // Mostrar el HTML resultante
                    echo $html_template;
                } else {
                    echo '<p>No se encontró una respuesta válida en formato JSON.</p>'; // Mensaje si no es un JSON válido
                }

                echo $args['after_widget']; 
            } else { 
                echo '<p>No hay respuestas disponibles para esta consulta.</p>'; 
            } 
        } else { 
            echo '<p>Consulta seleccionada no encontrada.</p>'; 
        } 
    } else { 
        echo '<p>No se ha seleccionado ninguna consulta.</p>'; 
    } 
}

    


    // Función para mostrar el formulario de configuración en el backend
public function form($instance) {
    global $wpdb;
    $queries_table = $wpdb->prefix . 'chatgpt_queries';

    // Obtener solo las consultas activas desde la base de datos
    $queries = $wpdb->get_results("SELECT * FROM $queries_table WHERE is_active = 1");

    // Obtener el ID de la consulta seleccionada
    $selected_query_id = isset($instance['query_id']) ? intval($instance['query_id']) : 0;

    // Mostrar el formulario de selección de consulta
    ?>
    <p>
        <label for="<?php echo $this->get_field_id('query_id'); ?>"><?php _e('Selecciona la consulta activa:'); ?></label>
        <select name="<?php echo $this->get_field_name('query_id'); ?>" id="<?php echo $this->get_field_id('query_id'); ?>" class="widefat">
            <option value="0"><?php _e('Seleccionar una consulta'); ?></option>
            <?php foreach ($queries as $query): ?>
                <option value="<?php echo esc_attr($query->id); ?>" <?php selected($selected_query_id, $query->id); ?>>
                    <?php echo esc_html($query->query_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <?php
}

    // Función para guardar los ajustes del widget
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['query_id'] = (!empty($new_instance['query_id'])) ? intval($new_instance['query_id']) : 0;
        return $instance;
    }
}

// Registrar el widget
function register_chatgpt_response_widget() {
    register_widget('ChatGPT_Response_Widget');
}
add_action('widgets_init', 'register_chatgpt_response_widget');


// Función para mostrar la página de opciones
function chatgpt_queries_options_page() {
    global $wpdb;
    $queries_table = $wpdb->prefix . 'chatgpt_queries';
    $table_name = $queries_table;

  // Manejar la ejecución de consultas
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_query_id'])) {
    $query_id = intval($_POST['execute_query_id']);
    $query = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$queries_table} WHERE id = %d",
        $query_id
    ));
    
    if ($query) {
        try {
            // Ejecuta la consulta específica
            get_chatgpt_response($query);
            
            // Mensaje de éxito
            add_action('admin_notices', function() use ($query) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>Consulta "' . esc_html($query->query_name) . '" ejecutada con éxito.</p>';
                echo '</div>';
            });
        } catch (Exception $e) {
            // Manejo de errores
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>Error al ejecutar la consulta: ' . esc_html($e->getMessage()) . '</p>';
                echo '</div>';
            });
        }
    }
}



// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");
 // Reindexar las respuestas
        $responses = $wpdb->get_results("SELECT id FROM $responses_table ORDER BY id ASC");
        foreach ($responses as $index => $response) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($response->id != $new_id) {
                $wpdb->update($responses_table, array('id' => $new_id), array('id' => $response->id));
            }
        }
    // Manejar la eliminación de respuestas
    if (isset($_POST['delete_response'])) {
        $response_id = intval($_POST['delete_response']);
        $wpdb->delete($responses_table, array('id' => $response_id));

        // Reindexar las respuestas
        $responses = $wpdb->get_results("SELECT id FROM $responses_table ORDER BY id ASC");
        foreach ($responses as $index => $response) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($response->id != $new_id) {
                $wpdb->update($responses_table, array('id' => $new_id), array('id' => $response->id));
            }
        }
    }

    if (isset($_POST['execute_query'])) {
        $query_id = intval($_POST['execute_query']);
        $query = $wpdb->get_row($wpdb->prepare("SELECT * FROM $queries_table WHERE id = %d", $query_id));
    
        if ($query) {
            // Aquí llamamos a la función que obtiene la respuesta de ChatGPT
            get_chatgpt_response($query);
        }
    }

// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");
// Manejar la edición de respuestas
if (isset($_POST['edit_response_id']) && isset($_POST['edit_response'])) {
    $edit_response_id = intval($_POST['edit_response_id']);
    $edit_response = wp_unslash($_POST['edit_response']);
    $edit_response = wp_kses_post($edit_response);
    $wpdb->update($responses_table, array('response' => $edit_response), array('id' => $edit_response_id));
}
// Obtener respuestas de la base de datos
$responses_table = $wpdb->prefix . 'chatgpt_responses';
$responses = $wpdb->get_results("SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id ORDER BY r.created_at DESC");



// Manejar la actualización de la API Key
if (isset($_POST['api_key']) && !empty($_POST['api_key'])) {
    $api_key = sanitize_text_field($_POST['api_key']);
    $result = update_option('chatgpt_api_key', $api_key);

    // Verificar si la API Key se guardó correctamente
    if ($result) {
        $success_message = "API Key guardada correctamente.";
    } else {
        $error_message = "Error al guardar la API Key.";
    }
}

// Obtener el valor actual de la API Key
$current_api_key = get_option('chatgpt_api_key', '');



// Manejar la actualización del intervalo de CRON
if (isset($_POST['cron_interval_hours'])) {
    $cron_interval_hours = intval($_POST['cron_interval_hours']);
    update_option('chatgpt_cron_interval', $cron_interval_hours);
    reprogram_chatgpt_cron($cron_interval_hours);
}

$all_queries = $wpdb->get_results("SELECT * FROM $queries_table");

// Obtener el ID de la consulta seleccionada para filtrar
$filter_query_id = isset($_GET['filter_query']) ? intval($_GET['filter_query']) : 0;

// Modificar la consulta SQL para incluir el filtro si se ha seleccionado una consulta
$responses_sql = "SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id";

if ($filter_query_id) {
    $responses_sql .= $wpdb->prepare(" WHERE q.id = %d", $filter_query_id);
}

$responses_sql .= " ORDER BY r.created_at DESC";

$responses = $wpdb->get_results($responses_sql);

// Obtener el valor actual del intervalo
$current_cron_interval = get_option('chatgpt_cron_interval', 2);

  // Manejar la creación de nuevas consultas
  if (isset($_POST['new_query_name']) && isset($_POST['new_query'])) { 
    $new_query_name = sanitize_text_field($_POST['new_query_name']); 
    $new_query = sanitize_textarea_field($_POST['new_query']); 

    // Obtener el ID más bajo disponible 
    $available_ids = $wpdb->get_col("SELECT id FROM $table_name ORDER BY id ASC"); 
    $new_id = 1; // Comenzar desde 1 
    while (in_array($new_id, $available_ids)) { 
        $new_id++; // Incrementar hasta encontrar un ID disponible 
    } 

    // Insertar la nueva consulta como inactiva y con el modelo por defecto
    $wpdb->insert($table_name, array( 
        'id' => $new_id, 
        'query_name' => $new_query_name, 
        'query' => $new_query, 
        'is_active' => 0,
        'model' => 'gpt-3.5-turbo' // Establecer el modelo por defecto
    )); 
}
// No cierres la etiqueta PHP si el archivo termina aquí
if (isset($_POST['delete_all_responses'])) {
    global $wpdb;

    // Eliminar todas las respuestas
    $wpdb->query("DELETE FROM $responses_table");

    // Redirigir
    wp_redirect(admin_url('options-general.php?page=chatgpt-queries'));
    exit;
}


      // Manejar la eliminación de consultas
      if (isset($_POST['delete_query'])) {
        $query_id = intval($_POST['delete_query']);
        $wpdb->delete($queries_table, array('id' => $query_id));

        // Reindexar las consultas
        $queries = $wpdb->get_results("SELECT id FROM $queries_table ORDER BY id ASC");
        foreach ($queries as $index => $query) {
            $new_id = $index + 1; // +1 para empezar desde 1
            if ($query->id != $new_id) {
                $wpdb->update($queries_table, array('id' => $new_id), array('id' => $query->id));
            }
        }
    }

// Manejar la activación/desactivación de consultas y la actualización de mostrar_respuesta
if (isset($_POST['toggle_active'])) {
    $query_id = intval($_POST['toggle_active']);
    $current_query = $wpdb->get_row("SELECT * FROM $table_name WHERE id = $query_id");

    // Cambiar el estado de la consulta seleccionada
    $new_status = $current_query->is_active ? 0 : 1; // Cambiar el estado
    $wpdb->update($table_name, array('is_active' => $new_status), array('id' => $query_id));
}

// Manejar la actualización de mostrar_respuesta
if (isset($_POST['mostrar_respuesta']) && isset($_POST['query_id'])) { 
    $query_id = intval($_POST['query_id']); 
    $mostrar_respuesta = sanitize_text_field($_POST['mostrar_respuesta']); 

    $wpdb->update($table_name, array('mostrar_respuesta' => $mostrar_respuesta), array('id' => $query_id)); 
}

    // Manejar la edición de consultas
    if (isset($_POST['edit_query_id']) && isset($_POST['edit_query_name']) && isset($_POST['edit_model']) && isset($_POST['edit_query'])) {
        $edit_query_id = intval($_POST['edit_query_id']);
        $edit_query_name = sanitize_text_field($_POST['edit_query_name']);
        $edit_query = sanitize_textarea_field($_POST['edit_query']);
        $edit_model = sanitize_textarea_field($_POST['edit_model']);

        $wpdb->update($table_name, array('query_name' => $edit_query_name, 'query' => $edit_query,'model' => $edit_model ), array('id' => $edit_query_id));
    }

  // Obtener el orden de las consultas desde la URL
  $order_by = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
  $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'ASC';

  // Validar el orden y el campo de orden
  $valid_orderby = array('query_name', 'created_at');
  if (!in_array($order_by, $valid_orderby)) {
      $order_by = 'id';
  }
  if (!in_array($order, array('ASC', 'DESC'))) {
      $order = 'ASC';
  }

    // Manejar la actualización del HTML del widget
    if (isset($_POST['edit_html_widget_id']) && isset($_POST['html_widget'])) {
    $edit_html_widget_id = intval($_POST['edit_html_widget_id']);
    $html_widget = wp_kses_post($_POST['html_widget']); // Sanitizar el HTML permitido

    $wpdb->update(
        $table_name,
        array('html_widget' => $html_widget),
        array('id' => $edit_html_widget_id)
    );
    }


  if (isset($_POST['model']) && isset($_POST['query_id'])) {
    $model = sanitize_text_field($_POST['model']);
    $query_id = intval($_POST['query_id']);

    // Actualizar el modelo en la base de datos
    $wpdb->update(
        $table_name,
        array('model' => $model), // Datos a actualizar
        array('id' => $query_id) // Condición
    );
}
  // Obtener las consultas con el orden especificado
  $queries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY $order_by $order");
    ?>

    <div class="wrap">
        <h1>Configuración de Consultas ChatGPT</h1>

        <form method="post" style="margin-bottom: 20px;">
            <h2>Añadir Nueva Consulta</h2>
            <h4 style="color: red;">Por Favor ingresar la consulta especificando que sea en formato JSON y especificar los campos a devolver<h4>
            <label for="new_query_name">Nombre de la Consulta:</label>
            <br>
            <input type="text" name="new_query_name" placeholder="Nombre..." required>
            <br>
            <label for="new_query">Consulta:</label>
            <br>
            <textarea name="new_query" placeholder="Ejemplo: Dame un JSON con 2 atributos, que sean Nombre y apellido respectivamente."
            style="width: 500px; min-height: 100px; max-height: 300px; overflow-y: auto; resize: none; padding: 10px; font-size: 16px; box-sizing: border-box;" 
            oninput="adjustHeight(this)" required></textarea>

            <script>
            function adjustHeight(textarea) {
                textarea.style.height = 'auto'; // Restablece la altura
                textarea.style.height = textarea.scrollHeight + 'px'; // Ajusta la altura a medida que el contenido crece
            }
            </script>
                <br>
                <input type="hidden" name="model" value="gpt-3.5-turbo"> <!-- El modelo por defecto se establece aquí -->
                <input type="submit" value="Añadir Consulta">
            </form>
<!-- Formulario para definir el intervalo del CRON -->
<form method="post" style="margin-bottom: 20px;">
    <h2>Configurar Intervalo del CRON</h2>
    <label for="cron_interval_hours">Intervalo en horas:</label>
    <br>
    <input type="number" name="cron_interval_hours" value="<?php echo esc_attr($current_cron_interval); ?>" placeholder="Coloca aquí tu API Key" required>
    <input type="submit" value="Guardar Intervalo">
</form>
<!-- Formulario para la API Key -->
<form method="post" style="margin-bottom: 20px;">
    <h2>Configurar API Key</h2>
    <label for="api_key">API Key:</label>
    <br>
    <input type="text" id="api_key" name="api_key" value="<?php echo esc_attr($current_api_key); ?>" required>
    <input type="submit" value="Guardar">
</form>
<!-- Mensaje de exito del ingreso de la API Key -->

<?php if (isset($success_message)) : ?>
    <div class="updated notice">
        <p><?php echo esc_html($success_message); ?></p>
    </div>
<?php endif; ?>



        <h2>Consultas Existentes</h2>
  <!-- Filtros de ordenación -->
  <div class="tablenav top">
            <div class="alignleft actions">
                <a href="?page=chatgpt-queries&orderby=query_name&order=ASC" class="button">Ordenar A-Z</a>
                <a href="?page=chatgpt-queries&orderby=query_name&order=DESC" class="button">Ordenar Z-A</a>
                <a href="?page=chatgpt-queries&orderby=id&order=ASC" class="button">Más Antiguas</a>
                <a href="?page=chatgpt-queries&orderby=id&order=DESC" class="button">Más Recientes</a>
            </div>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="2%">#</th>
                    <th width="10%">Nombre</th>
                    <th width="20%">GPT Prompt</th>
                    <th width="20%">HTML del Widget</th>
                    <th width="10%">Estado</th>
                    <th width="15%">Mostrar</th>
                    <th width="15%">Modelo</th>
                    <th width="20%">Acciones</th>


                </tr>
            </thead>
            <tbody>
                <?php if ($queries): ?>
                    <?php foreach ($queries as $query): ?>
                        <tr>
                            <!-- Columna 1: # -->
                            <td><?php echo esc_html($query->id); ?></td>
                            <!-- Columna 2: Nombre -->
                            <td>
                                <?php if (isset($_POST['edit_query_id']) && $_POST['edit_query_id'] == $query->id): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="edit_query_id" value="<?php echo esc_attr($query->id); ?>">
                                        <label for="edit_query_name">Nombre de la Consulta:</label>
                                        <input type="text" name="edit_query_name" value="<?php echo esc_attr($query->query_name); ?>" required>
                                        <label for="edit_query">Consulta:</label>
                                        <textarea name="edit_query" id="dynamicTextarea" required
                                        style="width: 350px; min-height: 100px; max-height: 300px; overflow-y: auto; resize: none; padding: 10px; font-size: 16px; box-sizing: border-box;"
                                        oninput="adjustHeight(this)"><?php echo esc_html($query->query); ?></textarea>
                                        <script>
                                        function adjustHeight(textarea) {
                                        textarea.style.height = 'auto'; // Restablece la altura
                                        textarea.style.height = textarea.scrollHeight + 'px'; // Ajusta la altura a medida que el contenido crece
                                        }
                                        // Llamamos a la función para ajustar la altura al cargar la página con el contenido de PHP
                                        window.onload = function() {
                                        const textarea = document.getElementById('dynamicTextarea');
                                        adjustHeight(textarea);
                                        }
                                        </script>
                                        <label for="edit_query">Modelo:</label>
                                        <input type="text" name="edit_model" value="<?php echo esc_attr($query->model); ?>" required>
                                        <br>
                                        <label for="edit_query">Mostrar Respuesta:</label>
                                        <br>
                                        <form method="post" style="display:inline;">
                                        <select name="mostrar_respuesta" onchange="this.form.submit();">
                                        <option value="ultima" <?php selected($query->mostrar_respuesta, 'ultima'); ?>>Última</option>
                                        <option value="aleatoria" <?php selected($query->mostrar_respuesta, 'aleatoria'); ?>>Aleatoria</option>
                                        </select>
                                        <input type="hidden" name="query_id" value="<?php echo esc_attr($query->id); ?>">
                                        <input type="submit" value="Guardar Cambios">
                                        <input type="button" value="Cerrar Menú" onclick="location.href='<?php echo esc_url(admin_url('options-general.php?page=chatgpt-queries')); ?>'">
                                    
                                    </form>
                                    
                                <?php else: ?>
                                    <?php echo esc_html($query->query_name); ?>
                                <?php endif; ?>
                            </td>

                            <!-- Columna 3: GPT Prompt -->
                            <td><?php echo esc_html($query->query); ?></td>

                            <!-- Columna 4: HTML del Widget -->
                            <td>
                            <?php if (isset($_POST['edit_html_widget_id']) && $_POST['edit_html_widget_id'] == $query->id): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="edit_html_widget_id" value="<?php echo esc_attr($query->id); ?>">
                                <textarea name="html_widget" id="dynamicTextarea" required
                                style="width: 250px; min-height: 100px; max-height: 300px; overflow-y: auto; resize: none; padding: 10px; font-size: 16px; box-sizing: border-box;"
                                oninput="adjustHeight(this)"><?php echo esc_textarea($query->html_widget); ?></textarea>
                                <script>
                                function adjustHeight(textarea) {
                                textarea.style.height = 'auto'; // Restablece la altura
                                textarea.style.height = textarea.scrollHeight + 'px'; // Ajusta la altura a medida que el contenido crece
                                }
                                // Ajustar la altura al cargar la página con el contenido de PHP
                                window.onload = function() {
                                const textarea = document.getElementById('dynamicTextarea');
                                adjustHeight(textarea);
                                }
                                </script>
                                <input type="submit" value="Guardar HTML">
                                <input type="button" value="Cerrar menú" onclick="location.href='<?php echo esc_url(admin_url('options-general.php?page=chatgpt-queries')); ?>'">
                            </form>
                            <?php else: ?>
                            <?php echo wp_kses_post($query->html_widget); ?>
                           
                            <?php endif; ?>
                            </td>

                             <!-- Columna 5: Estado -->
                            <td style="color: <?php echo $query->is_active ? 'green' : 
                            'red'; ?>;"><?php echo $query->is_active ? 'Activa' : 'Inactiva'; ?></td>

                            

                            <!-- Columna 5: Mostrar consulta -->
                            <td>
                            <?php echo esc_html($query->mostrar_respuesta); ?>
                            </form>
                            </td>
                                
                            <!-- Columna 6: Modelo -->
                            <td>
                            <?php echo esc_html($query->model); ?>
                            </form>
                            </td> 

                            <!-- Columna 7: Acciones -->
                            <td>
                             <!-- Botón para editar el HTML del Widget -->  
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="edit_html_widget_id" value="<?php echo esc_attr($query->id); ?>">
                                <button type="submit" title="Editar HTML del Widget">
                                    <span class="dashicons dashicons-media-code"></span>
                                </button>
                            </form>     
                            <!-- Activar/Desactivar -->
                            <form method="post" style="display:inline;">
                            <input type="hidden" name="toggle_active" value="<?php echo esc_attr($query->id); ?>">
                            <button type="submit" title="<?php echo $query->is_active ? 'Desactivar' : 'Activar'; ?>">
                            <span class="dashicons dashicons-<?php echo $query->is_active ? 'no' : 'yes'; ?>-alt"></span>
                            </button>
                            </form>
                            <!-- Eliminar -->
                            <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_query" value="<?php echo esc_attr($query->id); ?>">
                            <button type="submit" title="Eliminar" onclick="return confirm('¿Estás seguro de que deseas eliminar esta consulta?');">
                            <span class="dashicons dashicons-trash"></span>
                            </button>
                            </form>
                            <!-- Editar -->
                            <form method="post" style="display:inline;">
                            <input type="hidden" name="edit_query_id" value="<?php echo esc_attr($query->id); ?>">
                            <button type="submit" title="Editar">
                            <span class="dashicons dashicons-edit"></span>
                            </button>
                            </form>
                            <!-- Ejecutar Ahora -->
                            <form method="post" style="display:inline;">
    <input type="hidden" name="execute_query_id" value="<?php echo esc_attr($query->id); ?>">
    <button type="submit" title="Ejecutar Ahora">
        <span class="dashicons dashicons-update"></span>
    </button>
</form>
                        </tr>

                        
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        
                    
                    <td colspan="5">No hay consultas disponibles.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2>Respuestas a Consultas</h2>

<?php
// Obtener todas las consultas disponibles
$all_queries = $wpdb->get_results("SELECT * FROM $queries_table");

// Obtener el ID de la consulta seleccionada para filtrar
$filter_query_id = isset($_GET['filter_query']) ? intval($_GET['filter_query']) : 0;

// Obtener el número de respuestas a mostrar (por defecto 5)
$responses_per_page = isset($_GET['responses_per_page']) ? intval($_GET['responses_per_page']) : 5;

// Validar que el número de respuestas esté entre las opciones permitidas
$allowed_responses_per_page = array(5, 10, 20, 50, 100);
if (!in_array($responses_per_page, $allowed_responses_per_page)) {
    $responses_per_page = 5; // Valor por defecto
}

// Obtener el número total de respuestas
$total_responses_sql = "SELECT COUNT(*) FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id";

if ($filter_query_id) {
    $total_responses_sql .= $wpdb->prepare(" WHERE q.id = %d", $filter_query_id);
}

$total_responses = $wpdb->get_var($total_responses_sql);

// Calcular el número total de páginas
$total_pages = ceil($total_responses / $responses_per_page);

// Obtener el número de página actual
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// Asegurarse de que la página actual no exceda el número total de páginas
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}

// Calcular el desplazamiento (offset) para la consulta SQL
$offset = ($current_page - 1) * $responses_per_page;

// Modificar la consulta SQL para incluir el límite y el desplazamiento
$responses_sql = "SELECT r.id, r.response, r.created_at, q.query_name FROM $responses_table r JOIN $queries_table q ON r.query_id = q.id";

if ($filter_query_id) {
    $responses_sql .= $wpdb->prepare(" WHERE q.id = %d", $filter_query_id);
}

$responses_sql .= " ORDER BY r.created_at DESC LIMIT %d OFFSET %d";
$responses_sql = $wpdb->prepare($responses_sql, $responses_per_page, $offset);

$responses = $wpdb->get_results($responses_sql);
?>



<form method="get" action="" style="margin-bottom: 20px;">
    <!-- Mantener el parámetro 'page' en la URL -->
    <input type="hidden" name="page" value="chatgpt-queries">
    <label for="filter_query">Filtrar por consulta:</label>
    <select name="filter_query" id="filter_query">
        <option value="0">Todas las consultas</option>
        <?php foreach ($all_queries as $query): ?>
            <option value="<?php echo esc_attr($query->id); ?>" <?php selected($filter_query_id, $query->id); ?>>
                <?php echo esc_html($query->query_name); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="responses_per_page"> Mostrar:</label>
    <select name="responses_per_page" id="responses_per_page">
        <option value="5" <?php selected($responses_per_page, 5); ?>>5</option>
        <option value="10" <?php selected($responses_per_page, 10); ?>>10</option>
        <option value="20" <?php selected($responses_per_page, 20); ?>>20</option>
        <option value="50" <?php selected($responses_per_page, 50); ?>>50</option>
        <option value="100" <?php selected($responses_per_page, 100); ?>>100</option>
    </select>
    <input type="submit" value="Filtrar">
    
</form>
<form method="post" style="margin-bottom: 20px;">
    <input type="hidden" name="delete_all_responses" value="1">
    <button type="submit" onclick="return confirm('¿Estás seguro de que deseas eliminar todas las respuestas?');">Eliminar Todas</button>
</form>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th width="5%">#</th>
            <th width="40%">Respuesta</th>
            <th width="20%">Consulta</th>
            <th width="15%">Fecha/Hora</th>
            <th width="20%">Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($responses): ?>
            <?php foreach ($responses as $response): ?>
                <tr>
                    <td><?php echo esc_html($response->id); ?></td>
                    <td>
                        <?php if (isset($_POST['edit_response_id']) && $_POST['edit_response_id'] == $response->id): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="edit_response_id" value="<?php echo esc_attr($response->id); ?>">
                                <textarea name="edit_response" required><?php echo esc_html($response->response); ?></textarea>
                                <input type="submit" value="Guardar Cambios">
                                <input type="button" value="Cerrar Menú" onclick="location.href='<?php echo esc_url(admin_url('options-general.php?page=chatgpt-queries')); ?>'">
                            </form>
                        <?php else: ?>
                            <?php echo esc_html($response->response); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($response->query_name); ?></td>
                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $response->created_at ) ) ); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_response" value="<?php echo esc_attr($response->id); ?>">
                            <button type="submit" title="Eliminar" onclick="return confirm('¿Estás seguro de que deseas eliminar esta respuesta?');">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="edit_response_id" value="<?php echo esc_attr($response->id); ?>">
                            <button type="submit" title="Editar">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5"><?php _e('No hay respuestas disponibles.'); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php
// Generar los enlaces de paginación
if ($total_pages > 1) {
    echo '<div class="tablenav"><div class="tablenav-pages" style="float: left;">';
    // Construir los argumentos para la paginación
    $pagination_args = array(
        'paged' => '%#%',
        'page'  => 'chatgpt-queries',
    );

    // Si hay un filtro aplicado, mantenerlo en los enlaces
    if ($filter_query_id) {
        $pagination_args['filter_query'] = $filter_query_id;
    }

    // Mantener el parámetro responses_per_page en los enlaces de paginación
    if ($responses_per_page) {
        $pagination_args['responses_per_page'] = $responses_per_page;
    }

    // Construir la URL base para los enlaces de paginación
    $pagination_base = esc_url_raw(add_query_arg($pagination_args, admin_url('options-general.php')));

    echo paginate_links(array(
        'base'      => $pagination_base,
        'format'    => '',
        'current'   => $current_page,
        'total'     => $total_pages,
        'prev_text' => __('&laquo; Anterior'),
        'next_text' => __('Siguiente &raquo;'),
    ));

    echo '</div></div><div style="clear: both;"></div>';
}

ob_end_flush(); // Envía la salida
?>

<?php
}