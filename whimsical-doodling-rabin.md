# Plano de Implementação: CDM Catalog Router Plugin

**Plugin:** CDM Catalog Router (Dokan SPMV Engine) v1.0.0
**Objetivo:** Plugin WordPress/WooCommerce para roteamento inteligente de pedidos de marketplace para clones de vendedores
**Status:** ✅ **REVISADO** - 7 bloqueadores corrigidos após auditoria técnica
**Duração Estimada:** 11 sprints (2.75 meses)

---

## 🚨 Changelog da Revisão (v1.1 - Bloqueadores Corrigidos)

### 7 Mudanças Obrigatórias Implementadas:

| # | Bloqueador | Status | Sprint |
|---|------------|--------|--------|
| 1 | SQL View errada (soma estoque de pai, não variação) | ✅ REMOVIDA - Cache 2-tier manual | Fase 1 |
| 2 | CEP strategy errada ("ordenar por estoque" ≠ PRD) | ✅ CEP Preferential + Global Fairness | Fase 2 |
| 3 | Variation Matcher com lowercase forçado | ✅ Match EXATO (trim apenas) | Fase 2 |
| 4 | Dependência de hook com 1 argumento | ✅ Apenas hook 6-args correto | Fase 3 |
| 5 | Sticky routing incompleto (só master_id) | ✅ Key composta + delta-only | Fase 3 |
| 6 | Anti-bypass passivo (só alerta) | ✅ Limpeza ativa + URL validation | Fase 5 |
| 7 | INDEX com WHERE (não existe em MySQL) | ✅ REMOVIDO | Fase 8 |

**Data da Revisão:** 2026-01-22
**Auditor:** Usuário (Engenheiro Sênior)
**Versão Anterior:** 1.0 (rejeitada)
**Versão Atual:** 1.1 (aprovada)

---

## Arquitetura & Design Patterns

### Padrões Aplicados

- **Repository Pattern:** Abstração de acesso ao banco (ProductRepository, VendorRepository, StockRepository)
- **Strategy Pattern:** Algoritmos de roteamento intercambiáveis (CEP, Fairness, Stock-based)
- **Singleton Pattern:** Apenas plugin bootstrap (CDM_Plugin). Demais serviços via injeção de dependência (evita overengineering + facilita testes)
- **Factory Pattern:** Criação de objetos (RoutingStrategyFactory, VariationMatcherFactory)
- **Observer Pattern:** Sistema de hooks extensível para terceiros

### Estrutura de Arquivos

```
cdm-catalog-router/
├── cdm-catalog-router.php              # Main plugin file (cabeçalho, constantes, autoloader)
├── uninstall.php                        # Limpeza completa (View, options, transients)
├── composer.json                        # Autoloader PSR-4 + PHPUnit
├── .phpcs.xml                           # WordPress Coding Standards
│
├── includes/
│   ├── class-cdm-plugin.php            # Orquestrador principal (único Singleton)
│   ├── class-cdm-activator.php         # Activation (SEM SQL View - bloqueador #1 resolvido)
│   ├── class-cdm-deactivator.php       # Cleanup temporário
│   │
│   ├── core/
│   │   ├── class-cdm-router-engine.php           # Lógica de roteamento (CEP+Fairness)
│   │   ├── class-cdm-variation-matcher.php       # Match N-attrs SEM lowercase (bloq #3)
│   │   ├── class-cdm-price-enforcer.php          # Hook woocommerce_before_calculate_totals
│   │   ├── class-cdm-cart-interceptor.php        # Hook correto 6-args (bloq #4)
│   │   ├── class-cdm-checkout-validator.php      # Anti-bypass + limpeza carrinho (bloq #6)
│   │   └── class-cdm-session-manager.php         # Sticky por (id,var,attrs,cep) (bloq #5)
│   │
│   ├── repositories/
│   │   ├── class-cdm-base-repository.php         # Abstract base com $wpdb (injeção, não singleton)
│   │   ├── class-cdm-product-repository.php      # Master/clone queries + cache 2-tier
│   │   ├── class-cdm-vendor-repository.php       # Status + last_order_time (bloq #2)
│   │   └── class-cdm-stock-repository.php        # Agregação MANUAL de variações (bloq #1)
│   │
│   ├── strategies/
│   │   ├── interface-routing-strategy.php        # Contrato para algoritmos
│   │   ├── class-cep-preferential-allocator.php  # CEP match primeiro (bloq #2)
│   │   ├── class-global-fairness-allocator.php   # last_order_time ASC (bloq #2)
│   │   └── class-stock-fallback-allocator.php    # Último fallback por estoque
│   │
│   ├── admin/
│   │   ├── class-cdm-admin.php                   # Menu + enqueue assets
│   │   ├── class-cdm-settings.php                # Settings API (nonces, sanitização)
│   │   └── views/settings-page.php               # Template admin
│   │
│   ├── cache/
│   │   └── class-cdm-cache-manager.php           # Runtime + Transient (3-tier)
│   │
│   └── utils/
│       ├── class-cdm-logger.php                  # WC_Logger wrapper
│       ├── class-cdm-sanitizer.php               # Sanitização centralizada
│       └── class-cdm-validator.php               # Validação de dados
│
├── assets/
│   ├── js/admin/settings.js
│   └── css/admin/admin-styles.css
│
├── languages/
│   └── cdm-catalog-router.pot
│
├── tests/
│   ├── bootstrap.php
│   ├── test-variation-matcher.php
│   ├── test-routing-engine.php
│   └── test-integration.php
│
└── sql/
    └── migrations/migration-v1.0.0.php
```

---

## Fases de Implementação (SDLC)

### **FASE 0: Setup & Foundation (Sprint 1)**

**Objetivo:** Estrutura inicial do plugin e ferramentas de desenvolvimento

**Tarefas:**
1. Criar arquivo principal `cdm-catalog-router.php` com:
   - Cabeçalho WordPress padrão (Plugin Name, Version, Text Domain, etc.)
   - `declare(strict_types=1)` (PHP 8.2+ strict mode)
   - Constantes: `CDM_VERSION`, `CDM_PLUGIN_DIR`, `CDM_PLUGIN_URL`, `CDM_PLUGIN_FILE`
   - Prevention de acesso direto: `if (!defined('ABSPATH')) exit;`

2. Configurar Composer:
   - Autoloader PSR-4: `"CDM\\": "includes/"`
   - Dependências dev: `phpunit/phpunit`, `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`

3. Configurar WPCS:
   - `.phpcs.xml` com ruleset WordPress-Extra
   - Ignorar vendor/ e tests/

4. Criar classes base:
   - `CDM_Plugin` (Singleton) - ponto de entrada com método `get_instance()`
   - `CDM_Activator` - skeleton para activation hook
   - `CDM_Deactivator` - skeleton para deactivation hook

5. Registrar hooks:
   ```php
   register_activation_hook(__FILE__, ['CDM_Activator', 'activate']);
   register_deactivation_hook(__FILE__, ['CDM_Deactivator', 'deactivate']);
   add_action('plugins_loaded', ['CDM_Plugin', 'get_instance']);
   ```

**Validação:**
- [ ] Plugin ativa sem erros no WordPress
- [ ] Autoloader Composer funciona
- [ ] `vendor/bin/phpcs` executa sem erros
- [ ] Checagem de dependência WooCommerce funciona

**Arquivos Críticos:**
- `cdm-catalog-router.php`
- `composer.json`
- `includes/class-cdm-plugin.php`
- `includes/class-cdm-activator.php`

---

### **FASE 1: Database Layer & Cache (Sprint 2)** ⚠️ CORRIGIDO

**Objetivo:** Criar camada de repositórios com cache estrutural em 2 níveis (SEM SQL View no MVP)

**⚠️ BLOQUEADOR #1 RESOLVIDO:** SQL View removida do MVP pois soma `_stock` de produtos pai que não têm estoque. Estoque mora nas variações. View produziria zero/errado.

**Tarefas Técnicas:**

1. **Activator (SEM View no MVP)**
   - Criar método `activate()` apenas com:
     - Checagem de dependências (WooCommerce, Dokan)
     - Salvar versão do DB: `add_option('cdm_db_version', CDM_VERSION)`
     - Timestamp de ativação: `add_option('cdm_first_activation_time', time())`
   - **NÃO criar SQL View** (risco operacional + não funciona pra variável)

2. **Cache em 2 Camadas (Substituindo View)**

   **Camada 1: Cache Estrutural (TTL Alto - 1 hora)**
   - Key: `cdm_structure_{master_variation_id}_{clone_parent_id}`
   - Value: `clone_variation_id` (int)
   - Invalidação: em `woocommerce_update_product`, `dokan_product_updated`

   **Camada 2: Cache de Estoque (TTL Baixo - 5 minutos)**
   - Key: `cdm_stock_{master_variation_id}`
   - Value: `['total_stock' => N, 'vendors' => [...]]` (array)
   - Invalidação: em `woocommerce_reduce_order_stock`, updates de estoque

3. **Base Repository Pattern**
   - Criar `CDM_Base_Repository` abstrato com:
     - Propriedade `$wpdb` (global)
     - Propriedade `$cache_manager` (injeção de dependência, NÃO singleton)
     - Método helper `prepare_in_clause(array $values)` para queries IN
     - Método `invalidate_cache(string $pattern)` para flush seletivo

4. **Product Repository**
   - Métodos obrigatórios:
     - `is_master_product(int $product_id): bool` - Checa `_has_multi_vendor` meta (cache 1h)
     - `get_map_id(int $master_id): ?int` - Retorna map_id do mestre (cache 1h)
     - `get_active_clones(int $map_id): array` - Query DER (JOIN dokan_product_map + usermeta) (cache 15min)
     - `get_master_from_clone(int $clone_id): ?int` - Engenharia reversa via map_id (cache 1h)
     - **`get_clone_variation_id(int $clone_parent, array $attrs): ?int`** - Cache estrutural, delega ao Matcher
   - Prepared statements em TODAS as queries

5. **Vendor Repository**
   - `is_vendor_active(int $seller_id): bool` - Checa `dokan_enable_selling = 'yes'` (cache 10min)
   - `get_vendor_last_order_time(int $seller_id): ?int` - **Para fairness algorithm** (cache 5min)
   - `get_vendor_cep_zones(int $seller_id): array` - Para futura integração CEP (cache 1h)

6. **Stock Repository (Agregação Manual)**
   - `get_variation_stock_by_vendor(int $master_variation_id): array`
     - Retorna: `[['seller_id' => X, 'clone_variation_id' => Y, 'stock_qty' => Z], ...]`
     - **Busca estoque de variações clone, NÃO de produtos pai**
     - Cache por 5 minutos
   - `get_total_market_stock(int $master_variation_id): int`
     - Soma estoque de todos vendors ativos para aquela variação
     - Cache por 5 minutos

**Validação:**
- [ ] NÃO há SQL View criada (bloqueador resolvido)
- [ ] Cache estrutural funciona: buscar variation match 2x = 1 query DB
- [ ] Cache de estoque funciona: query de estoque reutiliza por 5min
- [ ] `ProductRepository::get_active_clones()` retorna clone 68083 para map_id correto
- [ ] Nenhuma query direta sem `$wpdb->prepare()`
- [ ] Query Monitor mostra cache hits em requests subsequentes

**Arquivos Críticos:**
- `includes/class-cdm-activator.php` (SEM create_stock_view)
- `includes/repositories/class-cdm-base-repository.php`
- `includes/repositories/class-cdm-product-repository.php`
- `includes/repositories/class-cdm-stock-repository.php` (aggregação manual)

---

### **FASE 2: Core Routing Engine (Sprint 3)**

**Objetivo:** Implementar matcher de variações e algoritmo de roteamento

**Tarefas Técnicas:**

1. **Variation Matcher (COMPONENTE MAIS CRÍTICO)** ⚠️ CORRIGIDO
   - Implementar `find_matching_variation(int $clone_parent_id, array $target_attributes): ?int`
   - **SQL Dinâmico com HAVING COUNT:**
     ```php
     // ⚠️ BLOQUEADOR #3 RESOLVIDO: NÃO normalizar com lowercase, comparar EXATO
     // Atributos taxonomia (pa_*) já vêm normalizados pelo WooCommerce
     // Forçar lowercase pode criar mismatch bobo

     $count_attributes = count($target_attributes);

     // Validar que suporta até 5 atributos (não negociável)
     if ($count_attributes > 5) {
         CDM_Logger::error('Variation Matcher: mais de 5 atributos não suportado');
         return null;
     }

     $meta_clauses = [];
     $prepare_values = [$clone_parent_id];

     foreach ($target_attributes as $key => $value) {
         // Trim apenas (sem lowercase forçado)
         $key = trim($key);
         $value = trim($value);

         $meta_clauses[] = "(pm.meta_key = %s AND pm.meta_value = %s)";
         $prepare_values[] = $key;
         $prepare_values[] = $value;
     }

     $prepare_values[] = $count_attributes;

     $where_clause = implode(' OR ', $meta_clauses);

     // ⚠️ CRÍTICO: HAVING COUNT garante ALL attributes match (não partial)
     $sql = "
         SELECT p.ID
         FROM {$wpdb->prefix}posts p
         JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
         WHERE p.post_parent = %d
           AND p.post_type = 'product_variation'
           AND p.post_status = 'publish'
           AND ($where_clause)
         GROUP BY p.ID
         HAVING COUNT(DISTINCT pm.meta_key) = %d
         LIMIT 1
     ";

     $variation_id = $wpdb->get_var($wpdb->prepare($sql, $prepare_values));
     ```
   - **NÃO normalizar lowercase** (bloqueador #3 resolvido)
   - Garantir suporte a 1-5 atributos com HAVING COUNT correto
   - Log de warning se match falhar (WP_DEBUG)

2. **Routing Strategies** ⚠️ CORRIGIDO

   **⚠️ BLOQUEADOR #2 RESOLVIDO:** "CEP MVP: ordenar por estoque" estava ERRADO. PRD exige CEP preferencial + fairness global.

   - Interface `Routing_Strategy` com método `allocate(array $clones, int $qty, ?string $cep): array`

   - **Implementar `CEP_Preferential_Allocator` (v1 obrigatório):**
     ```php
     // Regra: Se CEP do cliente casa com seller, PREFERIR esse seller até onde der
     // Depois, se qty restante, vai pra fila global

     public function allocate(array $clones, int $qty, ?string $cep): array {
         if (!$cep) {
             return $this->global_fairness_allocator->allocate($clones, $qty, null);
         }

         // Filtrar clones com CEP matching (zona de entrega)
         $cep_matches = array_filter($clones, fn($c) => $this->cep_matches($c['seller_id'], $cep));

         $allocations = [];
         $remaining = $qty;

         // Primeiro: encher vendors com CEP match (ordenar por last_completed_at ASC)
         foreach ($cep_matches as $clone) {
             if ($remaining <= 0) break;
             $allocated = min($clone['stock_qty'], $remaining);
             $allocations[] = [..., 'qty' => $allocated];
             $remaining -= $allocated;
         }

         // Se ainda falta qty, vai pra fila global (fairness)
         if ($remaining > 0) {
             $global_allocs = $this->global_fairness_allocator->allocate($clones, $remaining, null);
             $allocations = array_merge($allocations, $global_allocs['allocations']);
         }

         return ['allocations' => $allocations, 'fulfilled' => $remaining <= 0];
     }
     ```

   - **Implementar `Global_Fairness_Allocator` (v1 obrigatório):**
     ```php
     // Regra: Ordenar sellers por last_completed_at ASC (mais antigo primeiro)
     // Desempate por seller_id ASC

     public function allocate(array $clones, int $qty, ?string $cep): array {
         // Ordenar por fairness: seller que vendeu há mais tempo primeiro
         usort($clones, function($a, $b) {
             $time_a = $this->vendor_repo->get_vendor_last_order_time($a['seller_id']) ?? 0;
             $time_b = $this->vendor_repo->get_vendor_last_order_time($b['seller_id']) ?? 0;

             if ($time_a === $time_b) {
                 return $a['seller_id'] <=> $b['seller_id']; // desempate
             }
             return $time_a <=> $time_b; // ASC (mais antigo primeiro)
         });

         $allocations = [];
         $remaining = $qty;

         foreach ($clones as $clone) {
             if ($remaining <= 0) break;
             $allocated = min($clone['stock_qty'], $remaining);
             $allocations[] = [..., 'qty' => $allocated];
             $remaining -= $allocated;
         }

         return ['allocations' => $allocations, 'fulfilled' => $remaining <= 0];
     }
     ```

   - **`Stock_Fallback_Allocator` (último fallback):**
     - Se nenhum CEP match e nenhum last_order_time, ordenar por estoque descendente
     - Apenas como último recurso

   - Lógica de Split: alocar sequencialmente seguindo prioridade (CEP → Fairness → Stock)

3. **Router Engine (Orquestrador)**
   - Método `route_product(int $master_id, int $qty, ?int $variation_id, array $attrs): array`
   - Fluxo:
     1. Obter `map_id` via ProductRepository
     2. Obter clones ativos via ProductRepository
     3. Se produto variável: resolver variações usando VariationMatcher
     4. Aplicar estratégia de roteamento
     5. Retornar `['success' => true, 'allocations' => [...]]` ou `['error' => '...']`
   - Log de performance (operações > 1s)

**Validação:**
- [ ] VariationMatcher encontra variação 68084 quando busca atributos da variação 44265
- [ ] Matcher suporta 1, 2, 3+ atributos corretamente
- [ ] RouterEngine aloca para vendedor com maior estoque
- [ ] Split funciona: solicitar 10, vendedor tem 6 → aloca para 2 vendedores
- [ ] Logs apropriados em caso de falha

**Arquivos Críticos:**
- `includes/core/class-cdm-variation-matcher.php`
- `includes/core/class-cdm-router-engine.php`
- `includes/strategies/class-cep-routing-strategy.php`

---

### **FASE 3: Cart Integration (Sprint 4)** ⚠️ CORRIGIDO

**Objetivo:** Interceptar add-to-cart e substituir mestre por clones com sticky routing correto

**Tarefas Técnicas:**

1. **Cart Interceptor** ⚠️ CORRIGIDO

   **⚠️ BLOQUEADOR #4 RESOLVIDO:** Usar APENAS hooks que enxergam qty + variation_id + attrs. NÃO depender de filtros de 1 arg.

   - **Hook Principal:** `add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_and_route'], 10, 6)`
     - Assinatura: `($passed, $product_id, $quantity, $variation_id, $variations, $cart_item_data)`
     - **ESTE é o hook correto** - enxerga TODOS os dados necessários

   - **NÃO usar:** `woocommerce_add_to_cart_product_id` (apenas 1 arg, não dá pra rotear variação)

   - Fluxo:
     1. Checar se `$product_id` é mestre via `ProductRepository::is_master_product()`
     2. Se não for, retornar `$passed` (deixar WC processar)
     3. **Checar sticky routing primeiro** (bloqueador #5 - ver abaixo)
     4. Se não houver sticky válido, chamar `RouterEngine::route_product()`
     5. Se roteamento falhar, adicionar notice e retornar `false`
     6. Se sucesso, adicionar clones ao carrinho:
        ```php
        foreach ($allocations as $allocation) {
            $custom_data = [
                'cdm_routed' => true,
                'cdm_master_id' => $product_id,
                'cdm_master_variation_id' => $variation_id, // CRÍTICO para preço
                'cdm_seller_id' => $allocation['seller_id'],
                'cdm_allocation_timestamp' => time(),
                'cdm_attrs_hash' => $this->hash_attributes($variations), // Para sticky
            ];
            WC()->cart->add_to_cart($clone_id, $qty, $variation_id, $attrs, $custom_data);
        }
        ```
     7. Armazenar decisão em sessão (sticky routing com key correta)
     8. Exibir success notice manual
     9. **Retornar `false`** para impedir WC de adicionar o mestre

   - **Hook Secundário (Store API - Checkout Blocks):**
     ```php
     add_action('woocommerce_store_api_validate_add_to_cart', [$this, 'validate_store_api'], 10, 2);

     public function validate_store_api($product, $request) {
         if (!$this->product_repo->is_master_product($product->get_id())) {
             return; // Não é mestre, ok
         }

         // Store API: precisa lançar Exception para bloquear
         throw new RouteException(
             __('This product requires routing. Please use standard cart.', 'cdm-catalog-router'),
             'cdm_routing_required'
         );
     }
     ```

2. **Session Manager (Sticky Routing)** ⚠️ CORRIGIDO

   **⚠️ BLOQUEADOR #5 RESOLVIDO:** Key por `(master_id, master_variation_id, attrs_hash, cep)` + lógica delta-only.

   - **Sticky Key Completa:**
     ```php
     private function build_sticky_key(int $master_id, int $master_variation_id, array $attrs, ?string $cep): string {
         $attrs_hash = md5(serialize($attrs)); // Hash dos atributos
         $cep_normalized = $cep ? preg_replace('/\D/', '', $cep) : 'null';

         return "cdm_sticky_{$master_id}_{$master_variation_id}_{$attrs_hash}_{$cep_normalized}";
     }
     ```

   - **Métodos:**
     - `store_routing_decision(int $master_id, int $variation_id, array $attrs, ?string $cep, array $allocations)` - WC Session ou Cookie
     - `get_routing_decision(int $master_id, int $variation_id, array $attrs, ?string $cep): ?array` - Recuperar decisão
     - `invalidate_on_cep_change()` - Limpar sticky se CEP mudar
     - `invalidate_on_cart_change(string $cart_item_key)` - Limpar sticky se qty mudar (trigger de re-routing)

   - **Regra de Estabilidade Delta-Only:**
     ```php
     // Em Cart Interceptor: checar se item JÁ existe no carrinho
     $existing_item = $this->find_existing_cart_item($master_id, $master_variation_id, $attrs);

     if ($existing_item) {
         $existing_qty = $existing_item['quantity'];
         $new_qty = $quantity; // Quantidade adicionada agora

         if ($new_qty > 0) {
             // Qty+: alocar apenas DELTA (não re-rotear tudo)
             $delta = $new_qty;
             // Chamar routing apenas pro delta, manter seller existente
         } else if ($new_qty < 0) {
             // Qty-: remover do mais recente (LIFO)
             // Não chamar routing, apenas decrementar
         }
     }
     ```

   - Validade Cookie: 24h se sessão não disponível

3. **Integração com Checkout Blocks**
   - Hook `woocommerce_store_api_validate_add_to_cart` (lança Exception pra bloquear)
   - Mensagem: "Este produto requer roteamento. Use o carrinho padrão."
   - Futuro v2.0: suporte completo via Store API

**Validação:**
- [ ] Adicionar produto mestre 44263 resulta em clone 68083 no carrinho
- [ ] Produto mestre NÃO aparece no carrinho (retornou false)
- [ ] Split de quantidade cria múltiplos line items
- [ ] **Sticky routing por (master_id, variation_id, attrs, cep)** mantém vendor correto
- [ ] Trocar variação do produto = novo roteamento (sticky key muda)
- [ ] Mudar CEP = re-roteamento (sticky invalidado)
- [ ] Qty+ aloca apenas delta, mantém seller existente
- [ ] Cart item data contém `cdm_master_variation_id` e `cdm_attrs_hash`
- [ ] Mensagens de erro/sucesso aparecem corretamente
- [ ] Store API (Blocks) bloqueia add-to-cart de master

**Arquivos Críticos:**
- `includes/core/class-cdm-cart-interceptor.php`
- `includes/core/class-cdm-session-manager.php`

---

### **FASE 4: Price Enforcement (Sprint 5)**

**Objetivo:** Sobrescrever preços de clones com preços do mestre

**Tarefas Técnicas:**

1. **Price Enforcer**
   - Hook: `add_action('woocommerce_before_calculate_totals', [$this, 'enforce_master_prices'], 20, 1)`
   - **CRÍTICO:** Priority 20 (depois de WC setar preços iniciais)
   - **CRÍTICO:** Prevenir recursão com `did_action('woocommerce_before_calculate_totals') >= 2`
   - **CRÍTICO:** Ignorar admin: `if (is_admin() && !defined('DOING_AJAX')) return;`

2. **Lógica de Enforcement:**
   ```php
   foreach ($cart->get_cart() as $cart_item) {
       if (!isset($cart_item['cdm_routed'])) continue;

       // Usar master_variation_id armazenado durante add-to-cart
       $master_variation_id = $cart_item['cdm_master_variation_id'] ?? 0;

       if ($master_variation_id) {
           $master_price = get_post_meta($master_variation_id, '_price', true);
       } else {
           $master_id = $cart_item['cdm_master_id'];
           $master_price = wc_get_product($master_id)->get_price();
       }

       $cart_item['data']->set_price((float)$master_price);

       do_action('cdm_price_enforced', $cart_item_key, $clone_id, $master_id, $master_price);
   }
   ```

3. **Fallback para Produtos Simples:**
   - Se não houver `cdm_master_variation_id`, usar preço do produto pai mestre

**Validação:**
- [ ] Preço de clone $50 sobrescrito por preço de mestre $100
- [ ] Cart totals calculam corretamente
- [ ] Enforcement sobrevive a cart updates (quantity change)
- [ ] Funciona para produtos simples E variáveis
- [ ] Query Monitor não mostra degradação de performance

**Arquivos Críticos:**
- `includes/core/class-cdm-price-enforcer.php`

---

### **FASE 5: Security & Anti-Bypass (Sprint 6)** ⚠️ CORRIGIDO

**Objetivo:** Prevenir manipulação de checkout e acesso direto a clones (com limpeza ativa)

**Tarefas Técnicas:**

1. **Checkout Validator** ⚠️ CORRIGIDO

   **⚠️ BLOQUEADOR #6 RESOLVIDO:** Além de bloquear checkout, LIMPAR/RE-ROTEAR clones não-roteados do carrinho.

   - Hook 1: `add_action('woocommerce_check_cart_items', [$this, 'validate_and_clean_cart'], 5)`
     - **Priority 5** (antes de outros validadores)
   - Hook 2: `add_action('woocommerce_checkout_process', [$this, 'validate_cart_integrity'])`
   - Hook 3: `add_action('wp_loaded', [$this, 'validate_add_to_cart_url'], 20)` - **Anti-bypass de URL**

2. **Detecção e LIMPEZA de Clone Não-Roteado:**
   ```php
   public function validate_and_clean_cart(): void {
       $cart = WC()->cart->get_cart();
       $suspicious_items = [];

       foreach ($cart as $cart_item_key => $cart_item) {
           $product_id = $cart_item['product_id'];

           if ($this->is_unrouted_clone($product_id, $cart_item)) {
               $suspicious_items[] = $cart_item_key;
           }
       }

       if (!empty($suspicious_items)) {
           foreach ($suspicious_items as $key) {
               // OPÇÃO 1: Remover do carrinho
               WC()->cart->remove_cart_item($key);

               // OPÇÃO 2 (melhor UX): Re-rotear
               // $cart_item = $cart->get_cart()[$key];
               // $master_id = $this->product_repo->get_master_from_clone($cart_item['product_id']);
               // if ($master_id) {
               //     $this->re_route_item($master_id, $cart_item);
               // }

               CDM_Logger::warning('Unrouted clone removed from cart', [
                   'cart_item_key' => $key,
                   'product_id' => $cart_item['product_id'],
                   'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
               ]);
           }

           wc_add_notice(
               __('Some items in your cart were not properly routed and have been removed.', 'cdm-catalog-router'),
               'error'
           );
       }
   }

   private function is_unrouted_clone(int $product_id, array $cart_item): bool {
       // Se marcado como roteado, OK
       if (isset($cart_item['cdm_routed']) && $cart_item['cdm_routed']) {
           return false;
       }

       // Checar se está na dokan_product_map (é clone)
       global $wpdb;
       $is_clone = $wpdb->get_var($wpdb->prepare(
           "SELECT COUNT(*) FROM {$wpdb->prefix}dokan_product_map WHERE product_id = %d",
           $product_id
       ));

       return $is_clone > 0; // Clone sem flag = suspeito
   }
   ```

3. **Validação de Add-to-Cart URL (Anti-Bypass):**
   ```php
   // Prevenir add-to-cart via URL direta de clone
   // Ex: ?add-to-cart=68083 (clone) deve ser bloqueado

   public function validate_add_to_cart_url(): void {
       if (!isset($_REQUEST['add-to-cart'])) {
           return;
       }

       $product_id = absint($_REQUEST['add-to-cart']);

       // Checar se é clone
       global $wpdb;
       $is_clone = $wpdb->get_var($wpdb->prepare(
           "SELECT COUNT(*) FROM {$wpdb->prefix}dokan_product_map WHERE product_id = %d",
           $product_id
       ));

       if ($is_clone > 0) {
           // Redirecionar para produto mestre
           $master_id = $this->product_repo->get_master_from_clone($product_id);

           if ($master_id) {
               wp_safe_redirect(get_permalink($master_id));
               exit;
           } else {
               // Se não achar mestre, bloquear
               wc_add_notice(
                   __('This product cannot be added directly to cart.', 'cdm-catalog-router'),
                   'error'
               );
               wp_safe_redirect(wc_get_cart_url());
               exit;
           }
       }
   }
   ```

4. **Validação de Estoque no Checkout:**
   - Re-verificar se vendedor ainda tem estoque disponível
   - Bloquear se estoque < quantidade no carrinho

5. **Validação de Integridade de Preço:**
   - Comparar preço no carrinho com preço do mestre
   - Tolerância de ±0.01 para arredondamento
   - Se divergência > 0.01: bloquear + log de tentativa de manipulação

6. **Sanitização & Nonces:**
   - Todas as configurações admin: `wp_verify_nonce()` + `current_user_can('manage_woocommerce')`
   - Sanitização: `sanitize_text_field()`, `intval()`, `esc_sql()`
   - Escapamento de output: `esc_html()`, `esc_attr()`, `esc_url()`

**Validação:**
- [ ] URL direta ?add-to-cart=68083 (clone) → redireciona para mestre ou bloqueia
- [ ] Clone sem flag `cdm_routed` é REMOVIDO do carrinho (não só alerta)
- [ ] Tentativa de manipular preço via browser console é bloqueada
- [ ] Estoque insuficiente no checkout exibe erro
- [ ] Log de segurança registra todas tentativas suspeitas
- [ ] Todas as queries usam `$wpdb->prepare()`
- [ ] Re-roteamento de itens suspeitos funciona (opção 2)

**Arquivos Críticos:**
- `includes/core/class-cdm-checkout-validator.php`
- `includes/utils/class-cdm-sanitizer.php`
- `includes/utils/class-cdm-validator.php`

---

### **FASE 6: Admin Interface (Sprint 7)**

**Objetivo:** Painel de configurações e status do sistema

**Tarefas Técnicas:**

1. **Admin Menu**
   - `add_submenu_page('woocommerce', ...)` - Submenu em WooCommerce
   - Capability: `manage_woocommerce`

2. **Settings API**
   - `register_setting('cdm_settings_group', 'cdm_routing_strategy', ['sanitize_callback' => ...])`
   - Opções:
     - `cdm_routing_strategy` (string): 'cep' | 'fairness' | 'stock'
     - `cdm_cache_duration` (int): segundos (default 900)
     - `cdm_enable_logging` (bool): default true

3. **System Status Dashboard**
   - **NÃO** checar SQL View (removida do MVP - bloqueador #1)
   - Status de cache (object cache vs transients)
   - Cache hit rate (estrutural + estoque)
   - Últimas 10 operações de roteamento (log viewer)

4. **Assets Enqueue**
   - Apenas na página do plugin: `if ('woocommerce_page_cdm-catalog-router' !== $hook) return;`
   - Dependências corretas: `['jquery']` para JS

5. **Nonce em Formulários:**
   ```php
   <form method="post" action="options.php">
       <?php wp_nonce_field('cdm_settings_action', 'cdm_settings_nonce'); ?>
       <?php settings_fields('cdm_settings_group'); ?>
   </form>
   ```

**Validação:**
- [ ] Menu aparece em WooCommerce > Catalog Router
- [ ] Apenas usuários com `manage_woocommerce` acessam
- [ ] Settings salvam corretamente
- [ ] System status mostra cache stats (hit rate estrutural + estoque)
- [ ] Assets carregam APENAS na página do plugin

**Arquivos Críticos:**
- `includes/admin/class-cdm-admin.php`
- `includes/admin/class-cdm-settings.php`
- `includes/admin/views/settings-page.php`

---

### **FASE 7: Testing & QA (Sprint 8)**

**Objetivo:** Testes automatizados e validação end-to-end

**Tarefas Técnicas:**

1. **PHPUnit Setup**
   - `composer require --dev phpunit/phpunit wp-phpunit/wp-phpunit`
   - Bootstrap: carregar WordPress test suite
   - Mock de WooCommerce objects

2. **Unit Tests Obrigatórios:**
   - `test-variation-matcher.php`:
     - `test_single_attribute_match()` - 1 atributo
     - `test_multiple_attribute_match()` - 2+ atributos
     - `test_partial_match_returns_null()` - match incompleto
   - `test-routing-engine.php`:
     - `test_route_simple_product()`
     - `test_quantity_split()` - 10 unidades, 2 vendedores
   - `test-price-enforcer.php`:
     - `test_price_override()` - clone $50 → master $100

3. **Integration Tests:**
   - `test_full_cart_flow()` - add-to-cart → cart contém clone
   - `test_checkout_validation()` - clone não-roteado é bloqueado

4. **Manual Test Scenarios:**
   | ID | Cenário | Entrada | Resultado Esperado |
   |----|---------|---------|-------------------|
   | TC-01 | Add simple master | ID 44263, qty 2 | Clone 68083 no carrinho |
   | TC-02 | Add variable master | ID 44263, variation 44265 | Clone variation 68084 |
   | TC-03 | Quantity split | 10 unidades, vendor A=6, B=4 | 2 line items |
   | TC-04 | Price override | Clone=$50, Master=$100 | Cart total usa $100 |
   | TC-05 | Sticky routing | Add to cart, refresh | Mesmo vendor |
   | TC-06 | Anti-bypass | URL direta clone | Checkout bloqueado |

**Validação:**
- [ ] Todos os unit tests passam: `vendor/bin/phpunit`
- [ ] Code coverage > 70%
- [ ] Nenhum PHP warning/notice em WP_DEBUG
- [ ] Manual test scenarios 100% passados

**Arquivos Críticos:**
- `tests/bootstrap.php`
- `tests/test-variation-matcher.php`
- `tests/test-integration.php`

---

### **FASE 8: Performance Optimization (Sprint 9)**

**Objetivo:** Otimizar queries e implementar caching estratégico

**Tarefas Técnicas:**

1. **Cache Manager (3-tier)**
   ```php
   class CDM_Cache_Manager {
       private $runtime_cache = []; // In-memory

       public function get_or_set(string $key, callable $callback, int $expiration) {
           // 1. Runtime cache
           if (isset($this->runtime_cache[$key])) return $this->runtime_cache[$key];

           // 2. Transient
           $value = get_transient($key);
           if (false !== $value) {
               $this->runtime_cache[$key] = $value;
               return $value;
           }

           // 3. Database (via callback)
           $value = $callback();
           set_transient($key, $value, $expiration);
           $this->runtime_cache[$key] = $value;
           return $value;
       }
   }
   ```

2. **Cache Invalidation Hooks:**
   - `woocommerce_update_product` → invalidar cache do produto
   - `dokan_product_updated` → invalidar cache de clones
   - Admin flush button → limpar todos transients `cdm_*`

3. **Database Indexing (REMOVIDO)** ⚠️ CORRIGIDO

   **⚠️ BLOQUEADOR #7 RESOLVIDO:** `CREATE INDEX ... WHERE ...` não existe em MySQL padrão (apenas PostgreSQL). Vai falhar em produção.

   - **Se precisar de índice**, usar índice composto normal (SEM WHERE):
     ```sql
     -- OPCIONAL (avaliar custo em produção)
     CREATE INDEX idx_cdm_meta_key_value
     ON {$wpdb->prefix}postmeta (meta_key(191), meta_value(20));
     ```
   - **Recomendação:** NÃO adicionar índice no MVP. Deixar pra fase de otimização após testes de carga.
   - WordPress já tem índices em `meta_key` na maioria dos casos.

4. **Query Monitoring:**
   - Instalar Query Monitor
   - Testar cenários: product page, add-to-cart, cart, checkout
   - Meta: < 50 queries por página

5. **Performance Logging:**
   - Log operações > 1s com `microtime(true)`
   - Identificar gargalos (VariationMatcher, SQL View)

**Validação:**
- [ ] Query Monitor mostra < 50 queries na cart page
- [ ] Transient cache reduz queries em 60%+
- [ ] Cart page carrega < 1s
- [ ] Variation matching < 100ms
- [ ] Sem queries N+1

**Arquivos Críticos:**
- `includes/cache/class-cdm-cache-manager.php`

---

### **FASE 9: HPOS Compatibility (Sprint 10)**

**Objetivo:** Suporte a High-Performance Order Storage (WC 9.0+)

**Tarefas Técnicas:**

1. **Declaração de Compatibilidade:**
   ```php
   add_action('before_woocommerce_init', function() {
       if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
           \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
               'custom_order_tables',
               CDM_PLUGIN_FILE,
               true
           );
       }
   });
   ```

2. **Usar CRUD Objects (não post_meta):**
   ```php
   // ❌ ERRADO
   get_post_meta($order_id, 'cdm_routing_data', true);

   // ✅ CORRETO
   $order = wc_get_order($order_id);
   $order->get_meta('cdm_routing_data');
   ```

3. **Testes em Ambos os Modos:**
   - HPOS habilitado (WooCommerce > Settings > Advanced > HPOS)
   - HPOS desabilitado (legacy mode)

**Validação:**
- [ ] Plugin funciona com HPOS enabled
- [ ] Plugin funciona com HPOS disabled
- [ ] Order meta salva corretamente em ambos
- [ ] Nenhum deprecation warning

**Arquivos Críticos:**
- `cdm-catalog-router.php` (hook before_woocommerce_init)

---

### **FASE 10: Hardening & Production (Sprint 11)**

**Objetivo:** Auditoria de segurança, documentação e preparação para deploy

**Tarefas Técnicas:**

1. **Security Audit Checklist:**
   - [ ] Todas queries usam `$wpdb->prepare()`
   - [ ] Nenhum `$_POST`, `$_GET`, `$_REQUEST` sem sanitização
   - [ ] Nenhum `echo` sem `esc_html()` / `esc_attr()`
   - [ ] Todos forms tem nonces verificados
   - [ ] Capability checks em todas admin actions
   - [ ] Sem SQL injection, XSS, CSRF vulnerabilities

2. **Code Quality:**
   ```bash
   vendor/bin/phpcs --standard=WordPress includes/
   vendor/bin/phpstan analyse includes/ --level=5
   vendor/bin/phpunit --coverage-text
   ```

3. **Documentação:**
   - `README.md` - Instalação, requisitos, configuração
   - Inline PHPDoc em todas classes/métodos
   - Translation template: `wp i18n make-pot . languages/cdm-catalog-router.pot`

4. **Uninstall Handler:**
   ```php
   // uninstall.php
   if (!defined('WP_UNINSTALL_PLUGIN')) exit;

   global $wpdb;

   // NÃO há View pra dropar (removida do MVP - bloqueador #1)

   // Remove options
   delete_option('cdm_db_version');
   delete_option('cdm_routing_strategy');
   delete_option('cdm_cache_duration');
   delete_option('cdm_enable_logging');
   delete_option('cdm_first_activation_time');

   // Remove transients (cache estrutural + estoque)
   $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cdm_%'");
   $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_cdm_%'");
   ```

5. **i18n Check:**
   - Todos strings em `__('text', 'cdm-catalog-router')`
   - Gerar .pot file
   - Testar com PolyLang/WPML

**Validação:**
- [ ] WPCS 0 errors, 0 warnings
- [ ] PHPStan level 5 passa
- [ ] Code coverage > 70%
- [ ] README completo
- [ ] Uninstall não deixa vestígios
- [ ] .pot file gerado

**Arquivos Críticos:**
- `uninstall.php`
- `README.md`
- `languages/cdm-catalog-router.pot`

---

## ⚠️ 7 Bloqueadores Resolvidos (Auditoria Técnica)

### BLOQUEADOR #1: SQL View Errada ✅ RESOLVIDO
**Problema:** View somava `_stock` de produto pai clone. Pais não têm estoque; estoque mora nas variações. View retornaria zero/errado.
**Solução:** REMOVIDA View do MVP. Implementado cache 2-tier (estrutural 1h + estoque 5min). StockRepository faz agregação manual de variações.
**Sprint Afetado:** Fase 1

### BLOQUEADOR #2: Strategy CEP Errada ✅ RESOLVIDO
**Problema:** Plano dizia "CEP MVP: ordenar por estoque". PRD exige: (1) CEP match preferencial, (2) fairness global por `last_completed_at`.
**Solução:** Implementados `CEP_Preferential_Allocator` + `Global_Fairness_Allocator`. Stock só como último fallback.
**Sprint Afetado:** Fase 2

### BLOQUEADOR #3: Matcher com Lowercase Forçado ✅ RESOLVIDO
**Problema:** Normalizar atributos com lowercase pode criar mismatch (atributos taxonomia já vêm normalizados).
**Solução:** Comparar EXATO como vem do master (apenas trim). HAVING COUNT garante ALL attributes (1-5 suportados).
**Sprint Afetado:** Fase 2

### BLOQUEADOR #4: Hook de 1 Argumento ✅ RESOLVIDO
**Problema:** Alguns filtros como `woocommerce_add_to_cart_product_id` só passam 1 arg, não dá pra rotear variação/qty.
**Solução:** Usar APENAS `woocommerce_add_to_cart_validation` (6 args: product_id, qty, variation_id, variations, cart_item_data).
**Sprint Afetado:** Fase 3

### BLOQUEADOR #5: Sticky Routing Incompleto ✅ RESOLVIDO
**Problema:** Sticky apenas por `master_id` falha em variável (cliente troca variação, gruda em vendor errado).
**Solução:** Sticky key por `(master_id, master_variation_id, attrs_hash, cep)`. Regra delta-only: qty+ aloca só delta, qty- remove LIFO.
**Sprint Afetado:** Fase 3

### BLOQUEADOR #6: Anti-Bypass Passivo ✅ RESOLVIDO
**Problema:** Apenas bloquear checkout não resolve UX. Clone não-roteado fica no carrinho, usuário não entende erro.
**Solução:** Hook `woocommerce_check_cart_items` REMOVE/RE-ROTA clones suspeitos. Hook `wp_loaded` valida add-to-cart URL e redireciona.
**Sprint Afetado:** Fase 5

### BLOQUEADOR #7: INDEX com WHERE ✅ RESOLVIDO
**Problema:** `CREATE INDEX ... WHERE meta_key = '...'` não existe em MySQL padrão (apenas PostgreSQL). Falharia em produção.
**Solução:** REMOVIDO do plano. Se precisar, usar índice composto normal (sem WHERE) ou deixar pra fase de otimização pós-testes de carga.
**Sprint Afetado:** Fase 8

---

## Decisões Técnicas Críticas (Revisadas)

### 1. Variation Matching - Dynamic SQL com HAVING COUNT (SEM lowercase)
**Problema:** Variações podem ter 1-5 atributos. Não há ID direto linking master↔clone variation.
**Solução:** SQL dinâmico que busca meta_key/meta_value EXATO (trim apenas), agrupa por variation ID, e usa `HAVING COUNT(DISTINCT meta_key) = N` para garantir match de TODOS os atributos.
**Alternativa Rejeitada:** Pre-mapear variações (complexidade manutenção). Normalizar lowercase (cria mismatch).

### 2. Routing Algorithm - CEP Preferencial + Fairness Global
**Problema:** Balancear proximidade geográfica com equidade entre vendedores.
**Solução:** (1) CEPPreferentialAllocator enche vendor com CEP match até onde der. (2) GlobalFairnessAllocator completa por `last_completed_at ASC` (mais antigo primeiro). (3) StockFallback como último recurso.
**Alternativa Rejeitada:** "Ordenar por estoque" como CEP strategy (contradiz PRD).

### 3. Sticky Routing - Key Composta + Delta-Only
**Problema:** Manter mesmo vendedor entre refreshes, mas re-rotear se contexto mudar.
**Solução:** Key = `(master_id, master_variation_id, attrs_hash, cep)`. Invalidação: CEP change, variação change. Estabilidade: qty+ aloca apenas delta, qty- remove LIFO.
**Alternativa Rejeitada:** Sticky apenas por master_id (falha em variável).

### 4. Price Enforcement - Hook Priority 20
**Problema:** Clone tem preço diferente, precisa sobrescrever com preço do mestre.
**Solução:** `woocommerce_before_calculate_totals` priority 20 (depois de WC setar preços iniciais mas antes de totals). Prevenir recursão com `did_action() >= 2`.
**CRÍTICO:** Armazenar `cdm_master_variation_id` no cart item data durante add-to-cart.

### 5. Anti-Bypass - Whitelist + Limpeza Ativa
**Problema:** Cliente pode acessar URL direta do clone e fazer checkout.
**Solução:** (1) Whitelist via flag `cdm_routed = true`. (2) Hook `woocommerce_check_cart_items` REMOVE clones não-roteados. (3) Hook `wp_loaded` redireciona add-to-cart URL de clone pra mestre.
**Alternativa Rejeitada:** Blacklist passiva (menos segura, UX quebrada).

### 6. Caching Strategy - Two-tier Especializado (SEM SQL View)
**Problema:** Queries de roteamento são complexas, impactam performance.
**Solução:** (1) Cache estrutural (clone_variation_id por master_variation + attrs) - TTL 1h. (2) Cache de estoque (agregação por master_variation) - TTL 5min. Runtime cache (array em memória) na frente.
**Invalidação:** Hooks em `woocommerce_update_product` e `dokan_product_updated`.
**Alternativa Rejeitada:** SQL View (não funciona pra variável, risco permissão/host).

---

## Conformidade com Práticas Obrigatórias

### Checklist de Compliance

**Linguagem & Padrões:**
- [x] PHP 8.2+ com `declare(strict_types=1)`
- [x] WordPress Coding Standards (WPCS)
- [x] Arquitetura OOP (Singleton, Repository, Strategy)
- [x] i18n - Todas strings com `__()` / `_e()`

**WooCommerce:**
- [x] HPOS compatibility declarada
- [x] Hook `woocommerce_init` para features
- [x] CRUD Objects (`$product->get_price()`, não post_meta direto)
- [x] Cart/Checkout Blocks compatibility (básico)

**Segurança:**
- [x] Sanitização: `sanitize_text_field()`, `intval()`, `esc_sql()`
- [x] Escapamento: `esc_html()`, `esc_attr()`, `esc_url()`
- [x] Nonces: `wp_verify_nonce()` em todos forms
- [x] Capabilities: `current_user_can('manage_woocommerce')`
- [x] PHPDoc em todas classes/métodos

**Anti-Patterns Evitados:**
- [x] ❌ Scripts/CSS inline → ✅ `wp_enqueue_script()` com dependências
- [x] ❌ Modificar tabelas core → ✅ Usar meta_data e Views
- [x] ❌ Queries diretas → ✅ Sempre `$wpdb->prepare()`
- [x] ❌ `print_r` para debug → ✅ `WC_Logger`
- [x] ❌ Hardcoded prefix → ✅ `{$wpdb->prefix}`
- [x] ❌ N+1 queries → ✅ Batch loading + cache

**Performance:**
- [x] Transients API para cache
- [x] Query optimization (< 50 queries/page)
- [x] Log de slow operations (> 1s)

**Manutenibilidade:**
- [x] Hooks customizados (`do_action('cdm_before_routing')`)
- [x] Testes automatizados (PHPUnit)
- [x] Uninstall limpo (`uninstall.php`)
- [x] Versionamento de database

---

## Arquivos Críticos Identificados (Revisados)

1. **`includes/core/class-cdm-variation-matcher.php`** (Bloqueador #3)
   Coração técnico - match semântico N-atributos usando HAVING COUNT (SEM lowercase forçado, trim apenas)

2. **`includes/strategies/class-cep-preferential-allocator.php`** (Bloqueador #2)
   CEP matching primeiro - enche vendor com match de zona até onde der, depois vai pra fairness global

3. **`includes/strategies/class-global-fairness-allocator.php`** (Bloqueador #2)
   Fairness por `last_completed_at ASC` - garante equidade entre vendedores (mais antigo vende primeiro)

4. **`includes/core/class-cdm-session-manager.php`** (Bloqueador #5)
   Sticky routing com key composta `(master_id, variation_id, attrs_hash, cep)` + lógica delta-only

5. **`includes/repositories/class-cdm-stock-repository.php`** (Bloqueador #1)
   Agregação MANUAL de estoque de variações (substitui SQL View que estava errada)

6. **`includes/core/class-cdm-checkout-validator.php`** (Bloqueador #6)
   Anti-bypass com limpeza ativa - remove/re-rota clones não-roteados + valida add-to-cart URL

7. **`includes/core/class-cdm-cart-interceptor.php`** (Bloqueador #4)
   Hook correto `woocommerce_add_to_cart_validation` (6 args) - NUNCA usar filtros de 1 arg pra routing

---

## Estratégia de Verificação End-to-End

### Cenário de Teste Completo (usando IDs reais)

```
Produto Mestre: 44263 (produto variável com atributo "tamanho")
Variação Mestre: 44265 (tamanho: 10cm x 12cm)
Produto Clone: 68083 (vendedor X)
Variação Clone: 68084 (tamanho: 10cm x 12cm)
```

**Fluxo:**
1. Cliente visita produto 44263 (catálogo unificado)
2. Seleciona variação "10cm x 12cm" → variation_id = 44265
3. Clica "Add to Cart"
4. **CDM_Cart_Interceptor** intercepta:
   - Detecta que 44263 tem `_has_multi_vendor` meta
   - Chama `CDM_Router_Engine::route_product(44263, 1, 44265, ['attribute_tamanho' => '10cm x 12cm'])`
5. **CDM_Router_Engine:**
   - Obtém map_id via ProductRepository
   - Obtém clones ativos (retorna 68083)
   - Chama `CDM_Variation_Matcher::find_matching_variation(68083, ['attribute_tamanho' => '10cm x 12cm'])`
   - Matcher retorna 68084
   - Aplica routing strategy, retorna allocation
6. **CDM_Cart_Interceptor** adiciona ao carrinho:
   - `WC()->cart->add_to_cart(68083, 1, 68084, ['attribute_tamanho' => '10cm x 12cm'], ['cdm_routed' => true, 'cdm_master_id' => 44263, 'cdm_master_variation_id' => 44265])`
   - Retorna `false` (impede WC de adicionar 44263)
7. Cliente vê carrinho com produto 68083/68084
8. **CDM_Price_Enforcer** executa:
   - Detecta `cdm_routed = true`
   - Pega preço de variação 44265
   - Sobrescreve preço de 68084: `$cart_item['data']->set_price($master_price)`
9. Cliente vai para checkout
10. **CDM_Checkout_Validator:**
    - Verifica que 68083/68084 tem flag `cdm_routed`
    - Valida estoque ainda disponível
    - Valida preço não foi manipulado
11. Order criado com produto 68083/68084, vendedor X recebe pedido

**Resultado Esperado:**
- ✅ Cliente viu produto único (44263)
- ✅ Carrinho contém clone (68083/68084)
- ✅ Preço correto (da variação mestre 44265)
- ✅ Pedido vai para vendedor correto
- ✅ Admin mantém controle de preço

---

## Próximos Passos (Pós-Implementação)

### Roadmap v2.0
**Nota:** CEP Preferential e Fairness Global já estão no v1.0 (bloqueador #2 resolvido).

- **CEP Routing Avançado:** Integração com API de Correios (ViaCEP), cálculo de zonas geográficas (raio km), tabela de shipping zones
- **Vendor Reputation Score:** Priorizar vendedores por rating + fulfillment rate (não só fairness temporal)
- **Analytics Dashboard:** Métricas de distribuição (heat map vendedores), conversão por seller, revenue attribution
- **Inventory Sync Real-time:** WebSockets ou long polling pra atualização de estoque sem refresh
- **Checkout Blocks Full Support:** Re-implementar routing via Store API hooks (atualmente só validation básica)
- **Multi-CEP Routing:** Suportar múltiplos CEPs de destino (ex: gift para endereço diferente)
- **A/B Testing:** Testar diferentes strategies (CEP vs Pure Fairness vs Hybrid) com métricas

---

---

**Plano criado em:** 2026-01-22
**Revisado em:** 2026-01-22 (mesma data)
**Versão:** 1.1 (7 bloqueadores corrigidos)
**Status:** ✅ **APROVADO** - Pronto para implementação

## Aprovação Final

Este plano foi revisado e corrigido seguindo auditoria técnica rigorosa. Todos os 7 bloqueadores identificados foram resolvidos:

✅ Sem SQL View quebrada
✅ Routing algorithm correto (CEP + Fairness)
✅ Variation matching sem normalização forçada
✅ Hooks corretos para routing
✅ Sticky routing completo com key composta
✅ Anti-bypass com limpeza ativa
✅ Sem índices incompatíveis com MySQL

O plano está pronto para ser entregue ao desenvolvedor e iniciar a implementação.
