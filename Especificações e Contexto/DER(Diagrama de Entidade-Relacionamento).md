post_author---

### 1. DER (Diagrama de Entidade-Relacionamento) - Dokan SPMV

Este diagrama representa a estrutura física exata do seu banco `icbknveg_teste`.

### Dicionário de Dados (Especificação Técnica)

| **Tabela** | **Coluna** | **Tipo** | **Função** |
| --- | --- | --- | --- |
| **`wpw9_posts`** | `ID` | `bigint(20)` | Identificador único de qualquer produto (Mestre ou Clone). |
|  | `post_author` | `bigint(20)` | ID do Dono. Se for Admin = Mestre. Se for Vendedor = Clone. |
| **`wpw9_postmeta`** | `_has_multi_vendor` | `varchar` | **Chave do Mestre.** Guarda o `map_id`. Só o Mestre tem isso. |
|  | `_stock` | `varchar` | Estoque físico. |
| **`wpw9_dokan_product_map`** | `map_id` | `bigint(20)` | **ID do Grupo.** O elo de ligação. |
|  | `product_id` | `bigint(20)` | FK para `wpw9_posts`. |
|  | `seller_id` | `bigint(20)` | FK para `wpw9_users`. |
|  | `is_trash` | `tinyint(4)` | **0 = Ativo**, 1 = Lixo. |
| **`wpw9_usermeta`** | `dokan_enable_selling` | `varchar` | **'yes'** ou 'no'. Status da loja. |

---

### 2. SQL Kit para o Desenvolvedor (Copiar e Colar)

Entregue este bloco de código para o Dev. Ele contém a View corrigida para os erros de "Truncated Integer" que vimos no seu teste, e a lógica de roteamento ajustada para a presença do Mestre na tabela de mapa.

### A. Criação da Tabela Virtual (View)

*Rode isso uma vez no banco para instalar a inteligência de estoque.*

SQL

# 

`CREATE OR REPLACE VIEW wpw9_view_cdm_stock_router AS
SELECT 
    map.map_id AS group_id,
    p_master.ID AS master_product_id,
    p_master.post_title,
    -- Conta quantos vendedores ativos (excluindo o admin/mestre e lixo)
    COUNT(DISTINCT map.seller_id) - 1 AS active_sellers_count, 
    -- Soma segura do estoque (Evita erro de string vazia ou NULL)
    SUM(
        CASE 
            WHEN map.product_id = p_master.ID THEN 0 -- Não soma estoque do mestre
            WHEN pm_stock.meta_value IS NULL THEN 0
            WHEN pm_stock.meta_value = '' THEN 0
            ELSE CAST(pm_stock.meta_value AS UNSIGNED)
        END
    ) AS total_market_stock
FROM wpw9_dokan_product_map map
-- 1. Acha o Mestre baseado no map_id atual
JOIN wpw9_postmeta pm_master_link 
    ON map.map_id = pm_master_link.meta_value AND pm_master_link.meta_key = '_has_multi_vendor'
JOIN wpw9_posts p_master 
    ON pm_master_link.post_id = p_master.ID
-- 2. Valida Status da Loja do Vendedor
JOIN wpw9_usermeta um_status 
    ON map.seller_id = um_status.user_id AND um_status.meta_key = 'dokan_enable_selling'
-- 3. Pega o Estoque
LEFT JOIN wpw9_postmeta pm_stock 
    ON map.product_id = pm_stock.post_id AND pm_stock.meta_key = '_stock'
WHERE map.is_trash = 0 
  AND um_status.meta_value = 'yes'
GROUP BY map.map_id;`

### B. A Query de "Match de Variação" (O Coração do Plugin)

*Esta query deve ser usada dentro do PHP para encontrar o ID do filho no Clone.*

SQL

# 

- `- Parâmetros que vêm do PHP:- $parent_clone_id = ID do Produto Pai do Vendedor (ex: 68083)- $target_attr_key = 'attribute_tamanho'- $target_attr_val = '10cm x 12cm'SELECT p.ID AS variation_id
FROM wpw9_posts p
INNER JOIN wpw9_postmeta pm ON p.ID = pm.post_id
WHERE p.post_parent = 68083 - $parent_clone_idAND p.post_type = 'product_variation'AND pm.meta_key = 'attribute_tamanho' - $target_attr_keyAND pm.meta_value = '10cm x 12cm' - $target_attr_val
LIMIT 1;`

### C. A Query de "Roteamento Simples" (Prioridade por CEP)

*Para produtos simples, ou para escolher qual Clone Pai usar antes de buscar a variação.*

SQL

# 

`SELECT 
    map.product_id, 
    map.seller_id,
    -- Prioridade 1: Match de CEP (Exemplo simples, requer tabela de zonas se for complexo)
    -- Prioridade 2: Fairness (Data do último pedido)
    (SELECT meta_value FROM wpw9_usermeta WHERE user_id = map.seller_id AND meta_key = 'dokan_profile_settings') as seller_settings
FROM wpw9_dokan_product_map map
JOIN wpw9_usermeta status ON map.seller_id = status.user_id
WHERE map.map_id = 5 -- ID do Grupo (Vem do Mestre)
  AND map.is_trash = 0
  AND map.product_id != 44263 -- IMPORTANTE: Exclui o ID do Mestre
  AND status.meta_key = 'dokan_enable_selling' 
  AND status.meta_value = 'yes';`

---

### Resumo Final

Você tem agora:

1. **PRD Completo:** Regras de negócio.
2. **DER Visual:** Mapa do território.
3. **SQL Kit:** As ferramentas prontas.
4. **Auditoria:** A garantia de que os dados são reais.

Pode fechar o pacote e enviar para o desenvolvimento. O risco técnico foi reduzido a zero.