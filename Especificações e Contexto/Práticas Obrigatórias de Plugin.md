**Linguagem e Padrões de Código**

1. **Use PHP 8.2+ e Tipagem Estrita:** Aproveite as melhorias de performance e segurança. Use `declare(strict_types=1);` para evitar erros silenciosos de lógica [2].
2. **Siga as WordPress Coding Standards (WPCS):** Utilize ferramentas como PHP_CodeSniffer para garantir que seu código siga as normas oficiais de formatação e segurança [2].
3. **Arquitetura OOP:** Evite funções soltas. Organize seu plugin usando Programação Orientada a Objetos e o padrão de projeto Singleton ou Injeção de Dependência para gerenciar instâncias.
4. **Internacionalização (i18n):** Sempre use funções de tradução como `__()` e `_e()`. O WooCommerce é global; seu plugin também deve ser [2].

**Especificações do WooCommerce**

1. **Declare Compatibilidade com High-Performance Order Storage (HPOS):** Em 2026, o HPOS é o padrão. Certifique-se de que seu plugin não dependa da tabela `wp_posts` para pedidos [3].
2. **Use a Action `woocommerce_init`:** Nunca registre funcionalidades do WooCommerce antes que o próprio plugin base esteja carregado.
3. **Hook em CRUD Objects:** Utilize os métodos `get_`, `set_` e `save()` dos objetos de produto e pedido (ex: `$product->get_price()`) em vez de acessar o `post_meta` diretamente [1].
4. **Compatibilidade com o Bloco de Carrinho/Checkout:** O checkout tradicional via shortcode é legado. Desenvolva seu plugin para ser compatível com os novos **Cart and Checkout Blocks** [4].

**Obrigações e Segurança**

1. **Sanitização e Escapamento:** Regra de ouro: sanitize tudo que entra (`sanitize_text_field`) e escape tudo que sai (`esc_html`, `esc_attr`).
2. **Verificação de Nonces:** Proteja todas as ações de formulário e requisições AJAX com WordPress Nonces para evitar ataques CSRF.
3. **Checagem de Capacidades:** Sempre verifique se o usuário tem permissão para realizar uma ação usando `current_user_can('manage_woocommerce')`.
4. **Documentação Inline:** Use PHPDoc para explicar o que cada classe e método faz. Isso facilita a manutenção e a aprovação no repositório oficial.

**Maiores Erros (O que evitar)**

1. **Carregar Scripts em Todo o Admin:** Só enfileire seus arquivos JS e CSS nas páginas onde o plugin realmente atua para não degradar a performance do site [2].
2. **Modificar Tabelas Core:** Nunca altere a estrutura das tabelas nativas do WooCommerce. Se precisar de dados extras, use `Custom Tables` ou `Meta Data`.
3. **Ignorar o Log de Erros:** Não use `print_r` para debug em produção. Utilize a classe `WC_Logger` para registrar eventos importantes de forma profissional [1].

**Maiores Acertos (Diferenciais)**

1. **Crie Hooks de Terceiros:** Adicione seus próprios `do_action` e `apply_filters` no seu código. Isso permite que outros desenvolvedores estendam seu plugin sem modificá-lo.
2. **Interface Nativa (React/Gutenberg):** Use os componentes de UI do WordPress (baseados em React) para as telas de configuração, garantindo que o plugin pareça parte nativa do sistema.
3. **Suporte a Desinstalação Limpa:** Inclua um arquivo `uninstall.php` que remova todas as opções e tabelas criadas pelo plugin ao ser deletado [2].
4. **Testes Automatizados:** Implemente testes unitários com **PHPUnit**. Em 2026, a confiabilidade é o maior argumento de venda para plugins premium.
5. **Foco na Performance:** Minimize consultas ao banco de dados usando o `Transients API` do WordPress para cachear dados pesados que não mudam com frequência.

## 1. **Estrutura de Código**

Use sempre a estrutura padrão de plugin WordPress com um arquivo principal bem definido:

`php<?php
/**
 * Plugin Name: Seu Plugin Name
 * Description: Descrição do plugin
 * Version: 1.0.0
 * Author: Seu Nome
 * License: GPL-2.0+
 * Domain: seu-plugin
 * Text Domain: seu-plugin
 */

// Previne acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Define constantes
define('SEU_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SEU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEU_PLUGIN_VERSION', '1.0.0');`

## 2. **Segurança - Nonces (CRÍTICO)**

Sempre valide nonces em formulários e requisições AJAX. **Maior erro de iniciantes:**

`php// Ao salvar dados
wp_verify_nonce($_POST['_wpnonce'], 'seu_action');

// Ao fazer requisição AJAX
check_ajax_referer('seu_nonce_action');

// Gere nonce em formulários
wp_nonce_field('seu_action', '_wpnonce');`

## 3. **Sanitização de Dados**

Sempre sanitize dados do usuário **antes de armazenar**:

`php$email = sanitize_email($_POST['email']);
$text = sanitize_text_field($_POST['text']);
$html = wp_kses_post($_POST['html']);
$int = intval($_POST['number']);
$url = esc_url($_POST['url']);`

## 4. **Validação de Dados**

Valide dados **depois de sanitizar**:

`phpif (!is_email($email)) {
    wp_die('Email inválido');
}

if (strlen($text) < 5) {
    wp_die('Texto muito curto');
}`

## 5. **Hooks - Use com Sabedoria**

WordPress funciona em hooks. Use `do_action()` e `add_action()` para permitir extensibilidade:

`php// Seu plugin dispara ações
do_action('seu_plugin_before_process', $data);
do_action('seu_plugin_after_process', $result);

// Outros plugins podem se conectar
add_action('seu_plugin_before_process', 'minha_funcao');`

## 6. **Versionamento de Database**

Para atualizações seguras, implemente versionamento:

`phpfunction seu_plugin_activate() {
    $version = get_option('seu_plugin_version');
    
    if (!$version || $version < SEU_PLUGIN_VERSION) {
        // Rodas migrations
        seu_plugin_criar_tabelas();
        update_option('seu_plugin_version', SEU_PLUGIN_VERSION);
    }
}
register_activation_hook(__FILE__, 'seu_plugin_activate');`

## 7. **Evite JS/CSS Inline**

Use `wp_enqueue_script()` e `wp_enqueue_style()` com dependências:

`phpadd_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'seu-plugin-js',
        SEU_PLUGIN_URL . 'assets/script.js',
        ['jquery'], // Dependências
        SEU_PLUGIN_VERSION,
        true // No footer
    );
});`

## 8. **Use Transients para Cache**

Para dados que mudam raramente, use transients (muito mais rápido):

`php// Set
set_transient('seu_cache_key', $data, HOUR_IN_SECONDS);

// Get
$data = get_transient('seu_cache_key');

// Delete
delete_transient('seu_cache_key');`

## 9. **Prefix Tudo**

Use prefix único em todas as funções, hooks e opções (evita conflitos):

`php// Errado ❌
function adicionar_usuario() {}

// Correto ✅
function seu_plugin_adicionar_usuario() {}

// Nas opções
update_option('seu_plugin_setting', $value);

// Em hooks customizados
do_action('seu_plugin_init');`

## 10. **Integração com WooCommerce - Dependências**

Sempre verifique se WooCommerce está ativo:

`phpfunction seu_plugin_admin_notice() {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>';
        echo 'Seu Plugin requer WooCommerce ativo';
        echo '</p></div>';
    }
}
add_action('admin_notices', 'seu_plugin_admin_notice');`

## 11. **Hooks WooCommerce Principais**

Domine estes hooks para máxima flexibilidade:

`php// Produto
add_action('woocommerce_product_options_general_product_data', 'seu_plugin_product_field');
add_action('woocommerce_process_product_meta', 'seu_plugin_save_product_meta');

// Ordem
add_action('woocommerce_new_order', 'seu_plugin_on_new_order');
add_action('woocommerce_order_status_changed', 'seu_plugin_on_status_changed');

// Carrinho
add_filter('woocommerce_cart_item_price', 'seu_plugin_modify_price');`

## 12. **Trabalhe com Custom Post Types (CPT)**

Para dados complexos, use CPT em vez de opções:

`phpregister_post_type('seu_cpt', [
    'public' => true,
    'supports' => ['title', 'editor', 'custom-fields'],
    'has_archive' => true,
    'rewrite' => ['slug' => 'seu-cpt']
]);`

## 13. **Maior Erro: Queries Diretas ao Banco**

❌ **NUNCA faça:**

`php$results = $wpdb->get_results("SELECT * FROM wp_posts WHERE post_type = 'product'");`

✅ **SEMPRE use:**

`php$products = get_posts([
    'post_type' => 'product',
    'numberposts' => -1
]);`

## 14. **Logs e Debug**

Implemente logging profissional:

`phpfunction seu_plugin_log($message, $level = 'info') {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log("[SEU_PLUGIN-{$level}] " . print_r($message, true));
    }
}`

## 15. **Admin Pages com Settings API**

Use Settings API para painel admin robusto:

`phpregister_setting('seu_plugin_group', 'seu_plugin_opcoes');

add_settings_section(
    'seu_plugin_section',
    'Configurações',
    function() { echo 'Suas opções:'; },
    'seu_plugin_page'
);

add_settings_field(
    'seu_plugin_campo',
    'Campo:',
    function() {
        $value = get_option('seu_plugin_opcoes')['campo'] ?? '';
        echo "<input type='text' name='seu_plugin_opcoes[campo]' value='{$value}'/>";
    },
    'seu_plugin_page',
    'seu_plugin_section'
);`

## 16. **AJAX com Segurança**

Sempre verifique nonce e capacidades:

`phpadd_action('wp_ajax_seu_action', function() {
    check_ajax_referer('seu_nonce_action');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sem permissão');
    }
    
    // Sua lógica
    wp_send_json_success(['mensagem' => 'Sucesso']);
});`

## 17. **Teste de Compatibilidade**

Teste em múltiplas versões do WordPress (últimas 3 versões mínimo). Use `wp_get_environment_type()` para ambiente:

`phpif ('production' === wp_get_environment_type()) {
    // Apenas em produção
}`

## 18. **Internacionalização (i18n)**

Prepare seu plugin para tradução desde o início:

`phpload_plugin_textdomain('seu-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages/');

// Use sempre com domínio
echo __('Texto a traduzir', 'seu-plugin');
echo esc_html__('Com escape', 'seu-plugin');`

## 19. **Maior Acerto: Versionamento Automático**

Implemente sistema de versionamento de database para migrations suaves:

`php$db_version = get_option('seu_plugin_db_version', 0);

if ($db_version < 2) {
    // Migration para versão 2
    seu_plugin_migration_v2();
    update_option('seu_plugin_db_version', 2);
}`

## 20. **Performance - N+1 Queries**

❌ **PIOR erro em plugins:**

`phpforeach ($products as $product) {
    // Faz query DENTRO do loop
    $meta = get_post_meta($product->ID, 'meta_key');
}`

✅ **CORRETO:**

`php// Carrega tudo de uma vez
$meta_values = get_post_meta($product_ids);

foreach ($products as $product) {
    $meta = $meta_values[$product->ID] ?? [];
}`

---

## Resumo dos Erros Mais Comuns

| **Erro** | **Impacto** | **Solução** |
| --- | --- | --- |
| Sem nonces | Segurança comprometida | Sempre validar nonces |
| SQL direto | Risco SQL injection | Usar WP APIs |
| Sem sanitização | Vulnerabilidade XSS | Sanitizar sempre |
| N+1 queries | Site lento | Usar get_posts com cache |
| Conflitos de função | Erro fatal | Prefixar tudo |
| Sem versionamento | Atualizações quebram | Versionar DB |