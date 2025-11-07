<?php

namespace SyncPsToPsImporter\Support;

use Context;
use Configuration;
use Db;
use DbQuery;
use Language;
use Tools;
use Category;
use Product;
use Feature;
use FeatureValue;
use Manufacturer;
use StockAvailable;
use PrestaShopException;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Helpers de creación/lookup de entidades y asignación a producto.
 * Usa DbQuery para evitar SQL mal concatenado (adiós a "near 'LIMIT 1'").
 */
class DbEntities
{
    public function find_category_id_by_name($name, $id_lang)
    {
        $q = new DbQuery();
        $q->select('c.id_category')
          ->from('category', 'c')
          ->innerJoin('category_lang', 'cl', 'c.id_category = cl.id_category')
          ->where('cl.id_lang = '.(int)$id_lang)
          ->where('LOWER(cl.name) = \''.pSQL(Tools::strtolower($name)).'\'')
          ->orderBy('c.id_category DESC')
          ->limit(1);

        return (int)Db::getInstance()->getValue($q);
    }

    public function get_or_create_category($name, $id_parent = null, $active = 1)
    {
        $ctx = Context::getContext();
        $id_lang_default = (int)Configuration::get('PS_LANG_DEFAULT');
        $id_parent = $id_parent !== null ? (int)$id_parent : (int)Configuration::get('PS_HOME_CATEGORY');

        $id_category = $this->find_category_id_by_name($name, $id_lang_default);
        if ($id_category > 0) {
            return $id_category;
        }

        $category = new Category();
        $category->id_parent = $id_parent;
        $category->active = (int)$active;

        $langs = Language::getIDs(false);
        $i = 0;
        while (isset($langs[$i])) {
            $id_lang = (int)$langs[$i];
            $category->name[$id_lang] = $name;
            $category->link_rewrite[$id_lang] = Tools::link_rewrite($name);
            $i++;
        }

        if (!$category->add()) {
            throw new PrestaShopException('No se pudo crear la categoría: '.$name);
        }

        if (method_exists($category, 'addShop') && (int)$ctx->shop->id > 0) {
            $category->addShop($ctx->shop->id);
        }

        return (int)$category->id;
    }

    /**
     * $remote_category_names: array de nombres (["Parquet","Quick Step","Gris"])
     */
    public function assign_product_categories($id_product, array $remote_category_names)
    {
        $id_home = (int)Configuration::get('PS_HOME_CATEGORY');
        $ids = [$id_home];

        $i = 0;
        while (isset($remote_category_names[$i])) {
            $name = trim((string)$remote_category_names[$i]);
            if ($name !== '') {
                $ids[] = $this->get_or_create_category($name, $id_home, 1);
            }
            $i++;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $product = new Product((int)$id_product);
        $product->updateCategories($ids);

        return $ids;
    }

    public function get_or_create_feature($feature_name)
    {
        $id_lang_default = (int)Configuration::get('PS_LANG_DEFAULT');

        $q = new DbQuery();
        $q->select('f.id_feature')
          ->from('feature', 'f')
          ->innerJoin('feature_lang', 'fl', 'f.id_feature = fl.id_feature')
          ->where('fl.id_lang = '.(int)$id_lang_default)
          ->where('LOWER(fl.name) = \''.pSQL(Tools::strtolower($feature_name)).'\'')
          ->limit(1);

        $id_feature = (int)Db::getInstance()->getValue($q);
        if ($id_feature > 0) {
            return $id_feature;
        }

        $f = new Feature();
        $langs = Language::getIDs(false);
        $i = 0;
        while (isset($langs[$i])) {
            $id_lang = (int)$langs[$i];
            $f->name[$id_lang] = $feature_name;
            $i++;
        }

        if (!$f->add()) {
            throw new PrestaShopException('No se pudo crear la característica: '.$feature_name);
        }

        return (int)$f->id;
    }

    public function get_or_create_feature_value($id_feature, $value)
    {
        $id_lang_default = (int)Configuration::get('PS_LANG_DEFAULT');

        $q = new DbQuery();
        $q->select('fv.id_feature_value')
          ->from('feature_value', 'fv')
          ->innerJoin('feature_value_lang', 'fvl', 'fv.id_feature_value = fvl.id_feature_value')
          ->where('fv.id_feature = '.(int)$id_feature)
          ->where('fvl.id_lang = '.(int)$id_lang_default)
          ->where('LOWER(fvl.value) = \''.pSQL(Tools::strtolower($value)).'\'')
          ->limit(1);

        $id_value = (int)Db::getInstance()->getValue($q);
        if ($id_value > 0) {
            return $id_value;
        }

        $fv = new FeatureValue();
        $fv->id_feature = (int)$id_feature;
        $fv->custom = 0;

        $langs = Language::getIDs(false);
        $i = 0;
        while (isset($langs[$i])) {
            $id_lang = (int)$langs[$i];
            $fv->value[$id_lang] = $value;
            $i++;
        }

        if (!$fv->add()) {
            throw new PrestaShopException('No se pudo crear el valor de característica: '.$value);
        }

        return (int)$fv->id;
    }

    /**
     * $features: [['name'=>'Grosor','value'=>'8 mm'], ...]
     */
    public function assign_product_features($id_product, array $features)
    {
        Db::getInstance()->delete('feature_product', 'id_product='.(int)$id_product);

        $assigned = 0;
        $i = 0;
        while (isset($features[$i])) {
            $item = (array)$features[$i];
            $feat_name = trim((string)($item['name'] ?? ''));
            $feat_val  = trim((string)($item['value'] ?? ''));
            if ($feat_name !== '' && $feat_val !== '') {
                $id_feature = $this->get_or_create_feature($feat_name);
                $id_value   = $this->get_or_create_feature_value($id_feature, $feat_val);
                Product::addFeatureProduct((int)$id_product, (int)$id_feature, (int)$id_value);
                $assigned++;
            }
            $i++;
        }

        return $assigned;
    }

    public function get_or_create_manufacturer($name)
    {
        $q = new DbQuery();
        $q->select('id_manufacturer')
          ->from('manufacturer')
          ->where('LOWER(name) = \''.pSQL(Tools::strtolower($name)).'\'')
          ->limit(1);

        $id = (int)Db::getInstance()->getValue($q);
        if ($id > 0) {
            return $id;
        }

        $m = new Manufacturer();
        $m->name = $name;
        $m->active = 1;
        if (!$m->add()) {
            throw new PrestaShopException('No se pudo crear el fabricante: '.$name);
        }
        return (int)$m->id;
    }

    public function set_simple_stock($id_product, $qty)
    {
        $shop_id = (int)Context::getContext()->shop->id;
        StockAvailable::setQuantity((int)$id_product, 0, (int)$qty, $shop_id);
    }
}

