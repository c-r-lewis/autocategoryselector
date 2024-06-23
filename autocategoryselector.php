<?php

if (!defined('_PS_VERSION_'))
    exit;

class AutoCategorySelector extends Module
{
    private $updatingCategories = false;
    private $emailSent = false;

    public function __construct()
    {
        $this->name = 'autocategoryselector';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Charlotte Lewis';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0', 'max' => '8.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans('Auto Select Parent Categories', [], 'Modules.Autocategoryselector.Admin');
        $this->description = $this->trans('Automatically selects parent categories when creating or editing a product.', [], 'Modules.Autocategoryselector.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Autocategoryselector.Admin');

        if (!Configuration::get('AUTOCATEGORYSELECTOR_NAME')) {
            $this->warning = $this->trans('No name provided.', [], 'Modules.Autocategoryselector.Admin');
        }
    }

    public function hookActionProductSave($params)
    {
        if ($this->updatingCategories) {
            return;
        }

        $id_product = (int)Tools::getValue('id_product');
        if ($id_product == 0) return;

        $product = new Product($id_product);

        $categories = $product->getCategories();

        $processed_categories = [];
        foreach ($categories as $category_id) {
            $this->selectParentCategories($category_id, $processed_categories);
        }

        PrestaShopLogger::addLog('Categories to be assigned to product ID: ' . $id_product . ' - ' . implode(', ', array_unique($processed_categories)), 1);

        $this->updatingCategories = true;

        $product->updateCategories(array_unique($processed_categories));

        $this->updatingCategories = false;

        if (!$this->emailSent) {
            $this->sendAdminEmail($id_product);
            $this->emailSent = true;
        }
    }

    protected function selectParentCategories($id_category, &$processed_categories)
    {
        $root_category_id = 1;

        if ($id_category == $root_category_id || in_array($id_category, $processed_categories)) {
            return;
        }

        $category = new Category($id_category);
        $processed_categories[] = $id_category;

        if ($category->id_parent != 0 && $category->id_parent != $root_category_id) {
            PrestaShopLogger::addLog('Processing category ID: ' . $id_category . ', Parent ID: ' . $category->id_parent, 1, null, 'Category', $id_category);
            $this->selectParentCategories($category->id_parent, $processed_categories);
        }
    }

    protected function sendAdminEmail($id_product)
    {
        $product = new Product($id_product);

        $admin_email = Configuration::get('PS_SHOP_EMAIL');
        $admin_name = Configuration::get('PS_SHOP_NAME');

        $product_name = $product->name[Context::getContext()->language->id];

        $subject = $this->trans('A product has been updated or created', [], 'Modules.Autocategoryselector.Admin');
        $product_label = $this->trans('Product', [], 'Modules.Autocategoryselector.Admin');
        $parent_categories_label = $this->trans('Categories:', [], 'Modules.Autocategoryselector.Admin');
        $dear_admin = $this->trans('Dear Administrator,', [], 'Modules.Autocategoryselector.Admin');
        $changes_made = $this->trans('We would like to inform you that changes have been made to the product "{product_name}" (ID: {id_product}).', ['{product_name}' => $product_name, '{id_product}' => $id_product], 'Modules.Autocategoryselector.Admin');
        $review_details = $this->trans('Please review the product details and ensure that everything is correctly categorised.', [], 'Modules.Autocategoryselector.Admin');
        $thank_you = $this->trans('Thank you.', [], 'Modules.Autocategoryselector.Admin');

        $email_sent_by_module = $this->trans('This email was sent automatically by {module_name} module.', ['{module_name}' => $this->displayName], 'Modules.Autocategoryselector.Admin');

        $parent_categories = [];
        foreach ($product->getCategories() as $category_id) {
            $category = new Category($category_id);
            $parent_categories[] = $category->name[Context::getContext()->language->id];
        }

        $template_vars = [
            '{lang}' => Context::getContext()->language->iso_code,
            '{subject}' => $subject,
            '{product_label}' => $product_label,
            '{product_name}' => $product_name,
            '{id_product}' => $id_product,
            '{parent_categories_label}' => $parent_categories_label,
            '{parent_categories}' => implode(', ', $parent_categories),
            '{dear_admin}' => $dear_admin,
            '{changes_made}' => $changes_made,
            '{review_details}' => $review_details,
            '{thank_you}' => $thank_you,
            '{email_sent_by_module}' => $email_sent_by_module,
        ];

        try {
            // Send HTML email
            Mail::Send(
                Context::getContext()->language->id,
                'product_updated',
                $subject,
                $template_vars,
                $admin_email,
                $admin_name,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'autocategoryselector/mails/',
                false,
                null,
                null,
                null,
                null
            );


        } catch (Exception $e) {
            PrestaShopLogger::addLog('Email sending failed for product ID: ' . $id_product . ' with error: ' . $e->getMessage(), 3);
        }
    }
    
    public function install()
    {
        return parent::install() && $this->registerHook('actionProductSave');
    }
}
