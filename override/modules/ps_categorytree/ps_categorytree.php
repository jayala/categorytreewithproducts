<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_CategoryTreeOverride extends Ps_CategoryTree
{
    private function getCategories($category)
    {
        // This is the addition to the filters, the rest is a copy from ps_categorytree.php
        $productCountFilter = '
        AND (
            SELECT COUNT(cp.`id_product`) FROM `' . _DB_PREFIX_ . 'product` p ' . Shop::addSqlAssociation('product', 'p') . ' LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON p.`id_product` = cp.`id_product` WHERE product_shop.visibility IN ("both", "catalog") AND product_shop.active = 1 AND (
                cp.`id_category` = c.id_category OR cp.`id_category` IN (
                    select c2.id_category from `' . _DB_PREFIX_ . 'category` c2 where c2.id_parent = c.id_category
                ) OR cp.`id_category` IN (
                    select c2.id_category from `' . _DB_PREFIX_ . 'category` c2 where c2.id_parent IN (
                        select c3.id_category from `' . _DB_PREFIX_ . 'category` c3 where c3.id_parent = c.id_category
                    )
                )
            )
        ) > 0
        ';
        $range = '';
        $maxdepth = Configuration::get('BLOCK_CATEG_MAX_DEPTH');
        if (Validate::isLoadedObject($category)) {
            if ($maxdepth > 0) {
                $maxdepth += $category->level_depth;
            }
            $range = 'AND nleft >= ' . (int) $category->nleft . ' AND nright <= ' . (int) $category->nright;
        }

        $resultIds = [];
        $resultParents = [];
        $result = Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
			SELECT c.id_parent, c.id_category, cl.name, cl.description, cl.link_rewrite
			FROM `' . _DB_PREFIX_ . 'category` c
			INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (c.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . (int) $this->context->language->id . Shop::addSqlRestrictionOnLang('cl') . ')
			INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $this->context->shop->id . ')
			WHERE (c.`active` = 1 OR c.`id_category` = ' . (int) Configuration::get('PS_HOME_CATEGORY') . ')
			AND c.`id_category` != ' . (int) Configuration::get('PS_ROOT_CATEGORY') . '
			' . ((int) $maxdepth != 0 ? ' AND `level_depth` <= ' . (int) $maxdepth : '') . '
			' . $range . '
            ' . $productCountFilter . '
			AND c.id_category IN (
				SELECT id_category
				FROM `' . _DB_PREFIX_ . 'category_group`
				WHERE `id_group` IN (' . implode(', ', Customer::getGroupsStatic((int) $this->context->customer->id)) . ')
			)
            
			ORDER BY `level_depth` ASC, ' . (Configuration::get('BLOCK_CATEG_SORT') ? 'cl.`name`' : 'cs.`position`') . ' ' . (Configuration::get('BLOCK_CATEG_SORT_WAY') ? 'DESC' : 'ASC'));
        foreach ($result as &$row) {
            $resultParents[$row['id_parent']][] = &$row;
            $resultIds[$row['id_category']] = &$row;
        }

        return $this->getTree($resultParents, $resultIds, $maxdepth, ($category ? $category->id : null));
    }

    public function getWidgetVariables($hookName = null, array $configuration = [])
    {
        if (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') && !empty($this->context->cookie->last_visited_category) && $this->context->controller instanceof CategoryController) {
            $category = new Category($this->context->cookie->last_visited_category, $this->context->language->id);
        } else {
            $category = new Category((int) Configuration::get('PS_HOME_CATEGORY'), $this->context->language->id);
        }

        if (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') == static::CATEGORY_ROOT_PARENT && !$category->is_root_category && $category->id_parent) {
            $category = new Category($category->id_parent, $this->context->language->id);
        } elseif (Configuration::get('BLOCK_CATEG_ROOT_CATEGORY') == static::CATEGORY_ROOT_CURRENT_PARENT && !$category->is_root_category && !$category->getSubCategories($category->id, true)) {
            $category = new Category($category->id_parent, $this->context->language->id);
        }

        return [
            'categories' => $this->getCategories($category),
            'currentCategory' => $category->id,
        ];
    }
}
