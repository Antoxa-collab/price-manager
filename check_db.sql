SELECT id, marketplace_offer_id, pieces_per_sheet, quantity_in_pack
FROM product_mappings
WHERE marketplace = 'ozon'
LIMIT 10;
