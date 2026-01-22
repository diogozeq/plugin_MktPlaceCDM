---

# PRD Técnico: CDM Catalog Router (Dokan SPMV Engine) v2.0

Versão: 2.0 (Corrigida após revisão técnica)

Data: 21/01/2026

Contexto: Marketplace WooCommerce + Dokan Pro (SPMV Module)

Objetivo: Roteamento de pedidos para Clones ocultos mantendo a experiência de Catálogo Único.

---

## 1. Mapeamento de Arquitetura de Dados

### 1.1 Entidades

- **Produto Mestre:** `wp_posts`. Identificado por `_has_multi_vendor` na `postmeta`.
    - *Regra de Estoque:* Pai não tem estoque. Variações filhas não têm estoque físico (apenas lógico/capa).
    - *Regra de Preço:* O Pai exibe range. O preço real de transação está na **Variação Mestre**.
- **Produto Clone:** `wp_posts`. Vinculado via tabela `dokan_product_map`.
    - *Regra de Variação:* Não há ID de vínculo direto entre variação mestre e clone. O vínculo é **semântico (Atributos idênticos)**.

### 1.2 Status do Vendedor (Sanitização)

Para que um Clone seja considerado no roteamento, o vendedor deve estar ativo.

- **Tabela:** `wp_usermeta`
- **Chave Obrigatória:** `dokan_enable_selling` = `'yes'`.
- **Exclusão:** Não verificar módulos de "Vacation Mode" (fora do escopo).

---

## 2. Regras de Negócio Técnicas

### 2.1 Roteamento e Estabilidade

- **CEP Canônico:** Deve ser armazenado na sessão do WooCommerce (`WC()->session->set('cdm_routing_cep', $cep)`). Se não houver sessão, usar Cookie com validade de 24h.
- **Estabilidade (Sticky Routing):**
    - Uma vez alocado um Seller para um item no carrinho, **não trocar** o Seller num refresh de página, a menos que:
        1. O usuário altere o CEP.
        2. O usuário aumente a quantidade e o Seller atual não tenha saldo (trigger de Split).
        3. O Seller atual fique inativo/sem estoque no checkout.

### 2.2 Preço (Hard Enforcement)

O preço do carrinho deve ser **sobrescrito** pelo preço da **Variação Mestre** correspondente.

- *Motivo:* O preço do Clone (Seller) pode estar desatualizado ou manipulado. O Admin manda no preço.

### 2.3 Estoque e View

- **Produtos Simples:** Usar SQL View para performance.
- **Produtos Variáveis:** Devido à complexidade de atributos, o cálculo de estoque agregado deve ser feito sob demanda (AJAX/PHP) ou cacheado em Transient, não via View SQL complexa.

---

## 3. Implementação Técnica (Backend)

### 3.1 SQL View: Estoque Agregado (Apenas Produtos Simples)

*Nota para o Dev: Usar `{$wpdb->prefix}` ao criar, não hardcode `wp_`.*

SQL

# 

`/* Objetivo: Listar estoque total de produtos SIMPLES.
Para variáveis, o plugin deve checar via função PHP cdm_find_best_clone().
*/
CREATE OR REPLACE VIEW {$wpdb->prefix}view_cdm_simple_stock AS
SELECT 
    map.map_id as group_id,
    p_master.ID as master_product_id,
    COUNT(DISTINCT map.product_id) as active_sellers_count,
    SUM(CAST(pm_stock.meta_value AS UNSIGNED)) as total_stock
FROM {$wpdb->prefix}dokan_product_map map
JOIN {$wpdb->prefix}posts p_master 
    ON map.map_id = (SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE post_id = p_master.ID AND meta_key = '_has_multi_vendor' LIMIT 1)
JOIN {$wpdb->prefix}posts p_clone 
    ON map.product_id = p_clone.ID
JOIN {$wpdb->prefix}usermeta um_status 
    ON map.seller_id = um_status.user_id 
LEFT JOIN {$wpdb->prefix}postmeta pm_stock 
    ON map.product_id = pm_stock.post_id AND pm_stock.meta_key = '_stock'
WHERE p_clone.post_status = 'publish'
  AND map.is_trash = 0
  AND map.product_id != p_master.ID
  AND um_status.meta_key = 'dokan_enable_selling' AND um_status.meta_value = 'yes'
  AND p_clone.post_type = 'product' -- Apenas pais/simples
GROUP BY map.map_id;`

### 3.2 Core Logic: Match de Variação (Strict Mode)

Esta é a função crítica. Ela deve encontrar qual variação do Clone corresponde à variação do Mestre selecionada.

**Desafio:** O produto pode ter 1, 2 ou 5 atributos. O match deve ser **AND** (todos devem bater).

**SQL Dinâmico Obrigatório:**

PHP

# 

`function cdm_get_matching_variation_id($clone_parent_id, $target_attributes) {
    global $wpdb;
    // $target_attributes = ['attribute_pa_cor' => 'azul', 'attribute_pa_tam' => 'G']
    
    $count_attributes = count($target_attributes);
    $meta_clauses = [];
    
    foreach ($target_attributes as $key => $value) {
        $key = esc_sql($key);
        $value = esc_sql($value);
        // Monta condição para "ter este atributo"
        $meta_clauses[] = "(meta_key = '$key' AND meta_value = '$value')";
    }
    
    $where_clause = implode(' OR ', $meta_clauses); // Note: OR dentro do filtro, agrupado pelo post_id
    
    /* LÓGICA: Buscamos variações filhas do Clone.
       Filtramos as linhas da postmeta que batem com QUALQUER um dos atributos alvo.
       Agrupamos pelo ID da variação.
       O HAVING COUNT(*) deve ser igual ao número total de atributos buscados.
       Isso garante o match exato de todos os atributos.
    */
    $sql = "
        SELECT p.ID 
        FROM {$wpdb->prefix}posts p
        JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
        WHERE p.post_parent = %d 
          AND p.post_type = 'product_variation'
          AND ($where_clause)
        GROUP BY p.ID
        HAVING COUNT(*) = %d
        LIMIT 1
    ";
    
    return $wpdb->get_var($wpdb->prepare($sql, $clone_parent_id, $count_attributes));
}`

### 3.3 Hooks de Interceptação (Add to Cart & Split)

Não use `add_to_cart_product_id` para produtos variáveis ou lógica complexa. Use o fluxo de **Validação + Adição Programática**.

**Fluxo Sugerido:**

1. **Hook:** `woocommerce_add_to_cart_validation` (Priority 10).
2. **Lógica:**
    - Recebe `$product_id` (Mestre), `$quantity`, `$variation_id` (Mestre), `$variations` (Atributos).
    - Se for um Produto Catálogo (tem `_has_multi_vendor`):
        1. Roda lógica de Roteamento (`cdm_find_best_clone`).
        2. Encontra o(s) Clone(s) e Variações equivalentes.
        3. Faz o Split se necessário (ex: 4 do Seller A, 6 do Seller B).
        4. **Adiciona os itens reais ao carrinho:** `WC()->cart->add_to_cart($clone_id, $qty_a, ...)`
        5. **Retorna `false`**: Isso impede que o WooCommerce adicione o item original (Mestre) ao carrinho, evitando duplicação.
        6. Dispara notificação de sucesso manual (`wc_add_to_cart_message`).

### 3.4 Hook de Trava de Preço

Garante que, mesmo que o item no carrinho seja o Clone (ID 68084), o preço cobrado seja o da Variação Mestre (ID 44265).

PHP

# 

`add_action('woocommerce_before_calculate_totals', 'cdm_enforce_master_price', 20, 1);
function cdm_enforce_master_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;

    foreach ($cart->get_cart() as $cart_item) {
        $clone_product = $cart_item['data'];
        // Função auxiliar que faz engenharia reversa para achar o Mestre
        $master_variation_id = cdm_get_master_variation_from_clone($clone_product->get_id());
        
        if ($master_variation_id) {
            $master_price = get_post_meta($master_variation_id, '_price', true);
            $clone_product->set_price($master_price);
        }
    }
}`

---

## 4. Enforcement (Anti-Bypass)

O plugin deve garantir que nenhum pedido seja finalizado com um produto "Clone" se ele não tiver passado pelo roteador (ex: cliente acessou URL direta ou usou manipulador de query).

- **Hook:** `woocommerce_check_cart_items` ou `woocommerce_checkout_process`.
- **Regra:** Verificar se os itens no carrinho são Clones. Se sim, validar se o preço bate com o Mestre e se o estoque ainda é válido no Seller alocado.
- **URL:** Redirecionamento de URL de clone não é escopo deste plugin (será feito externamente), mas o bloqueio de checkout é.

---

## 5. Resumo das Responsabilidades do Dev

1. **Criar a View SQL** usando prefixo dinâmico.
2. **Implementar a função de Match Strict** (HAVING COUNT) para suportar N atributos.
3. **Implementar o Roteador** dentro do `add_to_cart_validation` para suportar Split de quantidade.
4. **Implementar a Trava de Preço** lendo da variação mestre.
5. **Testar** usando os IDs do "Gabarito de Teste" fornecido (Pai 44263 / Clone 68083).

---