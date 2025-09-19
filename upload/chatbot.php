<?php
// Simple chatbot endpoint
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Prevent any output before JSON
ob_start();

// Include OpenCart
require_once('config.php');
require_once(DIR_SYSTEM . 'startup.php');
require_once(DIR_SYSTEM . 'framework.php');

// Clear any output that might have been generated
ob_clean();

$json = [];

// Suggestions endpoint (data-driven quick replies)
if (isset($_GET['suggest']) && (int)$_GET['suggest'] === 1) {
    $json['suggestions'] = getQuickReplies();
    echo json_encode($json);
    exit;
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    $json['response'] = processMessage($message);
} else {
    $json['error'] = 'No message provided';
}

echo json_encode($json);

function processMessage($message) {
    $original_message = $message;
    $message = trim($message);
    $normalized = normalizeText(strtolower($message));
    
    // Category inquiry - check for exact matches first (PRIORITY)
    if ($normalized === 'danh muc' || $normalized === 'category' || $normalized === 'danh sach' || $normalized === 'danh muc san pham') {
        return showCategories();
    }
    
    // Check for category commands with additional text
    if (strpos($normalized, 'danh muc') !== false || strpos($normalized, 'category') !== false || strpos($normalized, 'loai') !== false) {
        // If it's "danh mục [name]" or "category [name]", show products in that category
        $category_name = str_replace(['danh mục', 'category', 'danh sách', 'loại', 'sản phẩm'], '', $message);
        $category_name = trim($category_name);
        if (!empty($category_name)) {
            return showCategoryProducts($category_name);
        }
        return showCategories();
    }
    
    // Compare products: "so sánh A vs B" or "compare A vs B"
    if (strpos($normalized, 'so sanh') !== false || strpos($normalized, 'compare') !== false || preg_match('/\svs\s/i', $message)) {
        $compare = compareProducts($original_message);
        if ($compare) {
            return $compare;
        }
    }
    
    // Check if message is a number (category ID)
    if (is_numeric($normalized)) {
        return showCategoryProducts($original_message);
    }
    
    // Check if message might be a category name
    $categories = getCategories();
    $search_term = $normalized;
    foreach ($categories as $category) {
        if (strpos(normalizeText(strtolower($category['name'])), $search_term) !== false) {
            return showCategoryProducts($original_message);
        }
    }
    
    // Product search (moved after category checks)
    if (strpos($normalized, 'tim') !== false || strpos($normalized, 'search') !== false) {
        return searchProducts($original_message);
    }
    
    // Price inquiry
    if (strpos($normalized, 'gia') !== false || strpos($normalized, 'price') !== false || strpos($normalized, 'cost') !== false) {
        return getPriceInfo($original_message);
    }
    
    // Stock inquiry
    if (strpos($normalized, 'con hang') !== false || strpos($normalized, 'stock') !== false || strpos($normalized, 'ton kho') !== false) {
        return getStockInfo($original_message);
    }
    
    // General help
    if (strpos($normalized, 'giup') !== false || strpos($normalized, 'help') !== false || strpos($normalized, 'huong dan') !== false) {
        return getHelp();
    }
    
    // If no specific keywords found, try to search for products
    return searchProducts($original_message);
}

function getProductPath($product_id) {
    global $registry;
    
    // Get the first category of the product
    $query = $registry->get('db')->query("SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.product_id = '" . (int)$product_id . "' LIMIT 1");
    
    if (!$query->num_rows) {
        return '';
    }
    
    $category_id = $query->row['category_id'];
    
    // Get the path for this category
    $path_query = $registry->get('db')->query("SELECT GROUP_CONCAT(cp.path_id ORDER BY cp.level SEPARATOR '_') as path FROM `" . DB_PREFIX . "category_path` cp WHERE cp.category_id = '" . (int)$category_id . "'");
    
    if ($path_query->num_rows) {
        return $path_query->row['path'];
    }
    
    return $category_id; // Fallback to category_id if no path found
}

function getCategories() {
    global $registry;
    
    $query = $registry->get('db')->query("SELECT c.category_id, cd.name, c.parent_id FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id) LEFT JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c.category_id = c2s.category_id) WHERE cd.language_id = '" . (int)$registry->get('config')->get('config_language_id') . "' AND c2s.store_id = '" . (int)$registry->get('config')->get('config_store_id') . "' AND c.status = '1' AND c.parent_id = '0' ORDER BY c.sort_order, LCASE(cd.name)");
    
    return $query->rows;
}

function getQuickReplies() {
    global $registry;
    $replies = [];
    
    // Top-level categories (first 3)
    $categories = getCategories();
    foreach (array_slice($categories, 0, 3) as $cat) {
        $replies[] = [
            'label' => '📁 ' . $cat['name'],
            'message' => 'danh mục ' . $cat['name']
        ];
    }
    
    // Top viewed products (first 3)
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    $top = $model->getProducts([
        'sort' => 'p.viewed',
        'order' => 'DESC',
        'start' => 0,
        'limit' => 3
    ]);
    foreach ($top as $p) {
        $replies[] = [
            'label' => '🔍 ' . $p['name'],
            'message' => 'tìm ' . $p['name']
        ];
    }
    
    // Compare top two products if available
    if (count($top) >= 2) {
        $a = $top[0]['name'];
        $b = $top[1]['name'];
        $replies[] = [
            'label' => '📊 So sánh ' . $a . ' vs ' . $b,
            'message' => 'so sánh ' . $a . ' vs ' . $b
        ];
    }
    
    // Help
    $replies[] = [
        'label' => '❓ Trợ giúp',
        'message' => 'giúp đỡ'
    ];
    
    return $replies;
}

function getCategoryProducts($category_id) {
    global $registry;
    
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    
    $data = [
        'filter_category_id' => $category_id,
        'filter_sub_category' => true,
        'start' => 0,
        'limit' => 10
    ];
    
    return $model->getProducts($data);
}

function getCategoryName($category_id) {
    global $registry;
    
    $query = $registry->get('db')->query("SELECT cd.name FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id) WHERE c.category_id = '" . (int)$category_id . "' AND cd.language_id = '" . (int)$registry->get('config')->get('config_language_id') . "' AND c.status = '1'");
    
    return $query->num_rows ? $query->row['name'] : '';
}

function showCategories() {
    global $registry;
    
    $categories = getCategories();
    
    if (empty($categories)) {
        return "Xin lỗi, không có danh mục sản phẩm nào.";
    }
    
    $response = "📁 **Danh sách danh mục sản phẩm:**\n\n";
    
    foreach ($categories as $index => $category) {
        $response .= ($index + 1) . ". " . $category['name'] . " (ID: " . $category['category_id'] . ")\n";
    }
    
    $response .= "\n💡 **Cách sử dụng:**\n";
    $response .= "• Gõ số thứ tự danh mục (ví dụ: 1, 2, 3...)\n";
    $response .= "• Hoặc gõ tên danh mục (ví dụ: Phones & PDAs)\n";
    $response .= "• Hoặc gõ 'danh mục [tên]' (ví dụ: danh mục Phones)\n";
    
    return $response;
}

function showCategoryProducts($message) {
    global $registry;
    
    // Extract category identifier from message
    $category_id = null;
    $category_name = '';
    
    // Check if message is a number (could be category ID or list index)
    if (is_numeric(trim($message))) {
        $number = (int)trim($message);
        // First, try as a real category ID
        $found_name = getCategoryName($number);
        if ($found_name) {
            $category_id = $number;
            $category_name = $found_name;
        } else {
            // Fallback: treat as list index from showCategories()
            $categories = getCategories();
            if ($number >= 1 && $number <= count($categories)) {
                $selected = $categories[$number - 1];
                $category_id = (int)$selected['category_id'];
                $category_name = $selected['name'];
            }
        }
    } else {
        // Search by category name
        $categories = getCategories();
        $search_term = strtolower(trim($message));
        
        foreach ($categories as $category) {
            if (strpos(strtolower($category['name']), $search_term) !== false) {
                $category_id = $category['category_id'];
                $category_name = $category['name'];
                break;
            }
        }
    }
    
    if (!$category_id) {
        return "❌ Không tìm thấy danh mục '" . $message . "'. Vui lòng thử lại hoặc gõ 'danh mục' để xem danh sách.";
    }
    
    $products = getCategoryProducts($category_id);
    
    if (empty($products)) {
        return "📁 **Danh mục: " . $category_name . "**\n\n❌ Không có sản phẩm nào trong danh mục này.";
    }
    
    $response = "📁 **Danh mục: " . $category_name . "**\n";
    $response .= "📦 Tìm thấy " . count($products) . " sản phẩm:\n\n";
    
    foreach ($products as $product) {
        $response .= "🔹 " . $product['name'] . "\n";
        
        // Calculate price with tax like website
        if ($registry->get('customer')->isLogged() || !$registry->get('config')->get('config_customer_price')) {
            $price_with_tax = $registry->get('tax')->calculate($product['price'], $product['tax_class_id'], $registry->get('config')->get('config_tax'));
            $response .= "💰 Giá: " . $registry->get('currency')->format($price_with_tax, $registry->get('config')->get('config_currency')) . "\n";
        } else {
            $response .= "💰 Giá: " . $registry->get('currency')->format($product['price'], $registry->get('config')->get('config_currency')) . "\n";
        }
        
        // Display stock status like website
        if ($product['quantity'] <= 0) {
            $response .= "❌ Hết hàng\n";
        } elseif (!$registry->get('config')->get('config_stock_display')) {
            $response .= "✅ Còn hàng\n";
        } else {
            $response .= "✅ Còn hàng: " . $product['quantity'] . " sản phẩm\n";
        }
        
        // Get product path and create correct URL
        $path = getProductPath($product['product_id']);
        if ($path) {
            $response .= "🔗 Xem chi tiết: " . html_entity_decode($registry->get('url')->link('product/category', 'language=' . $registry->get('config')->get('config_language') . '&path=' . $path . '&product_id=' . $product['product_id'])) . "\n\n";
        } else {
            $response .= "🔗 Xem chi tiết: " . html_entity_decode($registry->get('url')->link('product/product', 'language=' . $registry->get('config')->get('config_language') . '&product_id=' . $product['product_id'])) . "\n\n";
        }
    }
    
    return $response;
}

function searchProducts($message) {
    global $registry;
    
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    
    // Extract search terms
    $search_terms = extractSearchTerms($message);
    
    if (empty($search_terms)) {
        return "Bạn có thể cho tôi biết tên sản phẩm cụ thể để tìm kiếm không?";
    }
    
    // Pass 1: full-text search
    $data = [
        'filter_search' => implode(' ', $search_terms),
        'start' => 0,
        'limit' => 8
    ];
    $products = $model->getProducts($data);
    
    // Pass 2: fallback to name search (title), include description
    if (empty($products)) {
        $data2 = [
            'filter_name' => implode(' ', $search_terms),
            'filter_description' => true,
            'start' => 0,
            'limit' => 8
        ];
        $products = $model->getProducts($data2);
    }
    
    // Pass 3: try strongest keyword only
    if (empty($products) && count($search_terms) > 1) {
        // choose the longest term as strongest
        usort($search_terms, function($a, $b) { return strlen($b) <=> strlen($a); });
        $strong = $search_terms[0];
        $data3 = [
            'filter_name' => $strong,
            'filter_description' => true,
            'start' => 0,
            'limit' => 8
        ];
        $products = $model->getProducts($data3);
    }
    
    if (empty($products)) {
        $suggest = [];
        $suggest[] = "👉 Thử từ khóa ngắn hơn hoặc khác (ví dụ: 'tìm iPhone 14')";
        $suggest[] = "👉 Gõ 'danh mục' để xem danh mục";
        $suggest[] = "👉 Hoặc 'so sánh iPhone 13 vs iPhone 14'";
        return "Xin lỗi, tôi không tìm thấy sản phẩm nào phù hợp với từ khóa '" . implode(' ', $search_terms) . "'.\n\n" . implode("\n", $suggest);
    }
    
    $response = "Tôi tìm thấy " . count($products) . " sản phẩm phù hợp:\n\n";
    
    foreach ($products as $product) {
        $response .= "🔹 " . $product['name'] . "\n";
        
        // Calculate price with tax like website
        if ($registry->get('customer')->isLogged() || !$registry->get('config')->get('config_customer_price')) {
            $price_with_tax = $registry->get('tax')->calculate($product['price'], $product['tax_class_id'], $registry->get('config')->get('config_tax'));
            $response .= "💰 Giá: " . $registry->get('currency')->format($price_with_tax, $registry->get('config')->get('config_currency')) . "\n";
        } else {
            $response .= "💰 Giá: " . $registry->get('currency')->format($product['price'], $registry->get('config')->get('config_currency')) . "\n";
        }
        
        // Display stock status like website
        if ($product['quantity'] <= 0) {
            $response .= "❌ Hết hàng\n";
        } elseif (!$registry->get('config')->get('config_stock_display')) {
            $response .= "✅ Còn hàng\n";
        } else {
            $response .= "✅ Còn hàng: " . $product['quantity'] . " sản phẩm\n";
        }
        
        // Get product path and create correct URL
        $path = getProductPath($product['product_id']);
        if ($path) {
            $response .= "🔗 Xem chi tiết: " . html_entity_decode($registry->get('url')->link('product/category', 'language=' . $registry->get('config')->get('config_language') . '&path=' . $path . '&product_id=' . $product['product_id'])) . "\n\n";
        } else {
            $response .= "🔗 Xem chi tiết: " . html_entity_decode($registry->get('url')->link('product/product', 'language=' . $registry->get('config')->get('config_language') . '&product_id=' . $product['product_id'])) . "\n\n";
        }
    }
    
    $response .= "\nGợi ý: gõ 'so sánh [tên A] vs [tên B]' để so sánh.";
    
    return $response;
}

function getPriceInfo($message) {
    global $registry;
    
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    
    $search_terms = extractSearchTerms($message);
    
    if (empty($search_terms)) {
        return "Bạn muốn kiểm tra giá sản phẩm nào? Vui lòng cho tôi biết tên sản phẩm.";
    }
    
    $data = [
        'filter_search' => implode(' ', $search_terms),
        'start' => 0,
        'limit' => 3
    ];
    
    $products = $model->getProducts($data);
    
    if (empty($products)) {
        return "Không tìm thấy sản phẩm '" . implode(' ', $search_terms) . "' để kiểm tra giá.";
    }
    
    $response = "Thông tin giá sản phẩm:\n\n";
    
    foreach ($products as $product) {
        $response .= "🔹 " . $product['name'] . "\n";
        
        // Calculate price with tax like website
        if ($registry->get('customer')->isLogged() || !$registry->get('config')->get('config_customer_price')) {
            $price_with_tax = $registry->get('tax')->calculate($product['price'], $product['tax_class_id'], $registry->get('config')->get('config_tax'));
            $response .= "💰 Giá: " . $registry->get('currency')->format($price_with_tax, $registry->get('config')->get('config_currency')) . "\n";
            
            if ($product['special']) {
                $special_with_tax = $registry->get('tax')->calculate($product['special'], $product['tax_class_id'], $registry->get('config')->get('config_tax'));
                $response .= "🎯 Giá khuyến mãi: " . $registry->get('currency')->format($special_with_tax, $registry->get('config')->get('config_currency')) . "\n";
            }
        } else {
            $response .= "💰 Giá: " . $registry->get('currency')->format($product['price'], $registry->get('config')->get('config_currency')) . "\n";
            
            if ($product['special']) {
                $response .= "🎯 Giá khuyến mãi: " . $registry->get('currency')->format($product['special'], $registry->get('config')->get('config_currency')) . "\n";
            }
        }
        
        $response .= "\n";
    }
    
    return $response;
}


function getStockInfo($message) {
    global $registry;
    
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    
    $search_terms = extractSearchTerms($message);
    
    if (empty($search_terms)) {
        return "Bạn muốn kiểm tra tình trạng tồn kho sản phẩm nào?";
    }
    
    $data = [
        'filter_search' => implode(' ', $search_terms),
        'start' => 0,
        'limit' => 5
    ];
    
    $products = $model->getProducts($data);
    
    if (empty($products)) {
        return "Không tìm thấy sản phẩm '" . implode(' ', $search_terms) . "' để kiểm tra tồn kho.";
    }
    
    $response = "Tình trạng tồn kho:\n\n";
    
    foreach ($products as $product) {
        $response .= "🔹 " . $product['name'] . "\n";
        
        // Display stock status like website
        if ($product['quantity'] <= 0) {
            $response .= "❌ Hết hàng\n";
        } elseif (!$registry->get('config')->get('config_stock_display')) {
            $response .= "✅ Còn hàng\n";
        } else {
            $response .= "✅ Còn hàng: " . $product['quantity'] . " sản phẩm\n";
        }
        
        $response .= "\n";
    }
    
    return $response;
}

function getHelp() {
    return "🤖 Tôi có thể giúp bạn:\n\n" .
           "🔍 Tìm kiếm sản phẩm: 'tìm [tên sản phẩm]'\n" .
           "💰 Kiểm tra giá: 'giá [tên sản phẩm]'\n" .
           "📁 Xem danh mục: 'danh mục' hoặc 'category'\n" .
           "📦 Xem sản phẩm trong danh mục: 'danh mục [tên]' hoặc gõ số thứ tự\n" .
           "📦 Kiểm tra tồn kho: 'còn hàng [tên sản phẩm]'\n" .
           "📊 So sánh sản phẩm: 'so sánh [A] vs [B]'\n" .
           "❓ Hướng dẫn: 'giúp đỡ'\n\n" .
           "💡 **Ví dụ sử dụng danh mục:**\n" .
           "• Gõ 'danh mục' để xem tất cả danh mục\n" .
           "• Gõ '1' để xem sản phẩm trong danh mục đầu tiên\n" .
           "• Gõ 'Phones' để xem sản phẩm trong danh mục Phones\n" .
           "• Gõ 'danh mục Phones' để xem sản phẩm trong danh mục Phones\n\n" .
           "Bạn muốn tìm hiểu gì?";
}

function extractSearchTerms($message) {
    // Remove common words (normalized)
    $stop_words = ['tim','search','gia','price','con','hang','stock','ton','kho','so','sanh','vs','compare'];
    $clean = preg_replace('/[\.,;:!\?\-\(\)\[\]\{\}]+/u', ' ', $message);
    $words = preg_split('/\s+/u', trim($clean));
    $terms = [];
    
    foreach ($words as $word) {
        $plain = strtolower($word);
        $plain = normalizeText($plain);
        if ($plain !== '' && !in_array($plain, $stop_words)) {
            $terms[] = $word; // keep original token for nicer display/search
        }
    }
    
    if (empty($terms)) {
        $terms = [trim($message)];
    }
    
    return $terms;
}

function normalizeText($text) {
    // Convert Vietnamese accents to ASCII for robust matching
    $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
        'đ'=>'d',
        'À'=>'A','Á'=>'A','Ạ'=>'A','Ả'=>'A','Ã'=>'A','Â'=>'A','Ầ'=>'A','Ấ'=>'A','Ậ'=>'A','Ẩ'=>'A','Ẫ'=>'A','Ă'=>'A','Ằ'=>'A','Ắ'=>'A','Ặ'=>'A','Ẳ'=>'A','Ẵ'=>'A',
        'È'=>'E','É'=>'E','Ẹ'=>'E','Ẻ'=>'E','Ẽ'=>'E','Ê'=>'E','Ề'=>'E','Ế'=>'E','Ệ'=>'E','Ể'=>'E','Ễ'=>'E',
        'Ì'=>'I','Í'=>'I','Ị'=>'I','Ỉ'=>'I','Ĩ'=>'I',
        'Ò'=>'O','Ó'=>'O','Ọ'=>'O','Ỏ'=>'O','Õ'=>'O','Ô'=>'O','Ồ'=>'O','Ố'=>'O','Ộ'=>'O','Ổ'=>'O','Ỗ'=>'O','Ơ'=>'O','Ờ'=>'O','Ớ'=>'O','Ợ'=>'O','Ở'=>'O','Ỡ'=>'O',
        'Ù'=>'U','Ú'=>'U','Ụ'=>'U','Ủ'=>'U','Ũ'=>'U','Ư'=>'U','Ừ'=>'U','Ứ'=>'U','Ự'=>'U','Ử'=>'U','Ữ'=>'U',
        'Ỳ'=>'Y','Ý'=>'Y','Ỵ'=>'Y','Ỷ'=>'Y','Ỹ'=>'Y',
        'Đ'=>'D'
    ];
    $text = strtr($text, $map);
    // collapse spaces
    $text = preg_replace('/\s+/',' ', $text);
    return trim($text);
}

function compareProducts($message) {
    global $registry;
    $m = normalizeText(strtolower($message));
    // remove leading verbs
    $m = preg_replace('/^(so sanh|compare)\s+/i', '', $m);
    // Split by vs
    $parts_norm = preg_split('/\s+vs\s+/i', $m);
    if (!$parts_norm || count($parts_norm) < 2) {
        return '';
    }
    // Also split original message to keep product names pretty
    $orig = preg_replace('/^(so sánh|so sanh|compare)\s+/i', '', $message);
    $parts_orig = preg_split('/\s+vs\s+/i', $orig);
    $nameA = trim($parts_orig[0] ?? '');
    $nameB = trim($parts_orig[1] ?? '');
    if ($nameA === '' || $nameB === '') {
        return '';
    }
    
    $registry->get('load')->model('catalog/product');
    $model = $registry->get('model_catalog_product');
    
    $find = function($q) use ($model) {
        $data = [
            'filter_search' => $q,
            'start' => 0,
            'limit' => 1
        ];
        $rows = $model->getProducts($data);
        return $rows ? $rows[0] : null;
    };
    $a = $find($nameA);
    $b = $find($nameB);
    
    if (!$a && !$b) {
        return "Không tìm thấy sản phẩm để so sánh. Hãy nhập: 'so sánh [A] vs [B]'.";
    }
    if (!$a || !$b) {
        $missing = !$a ? $nameA : $nameB;
        return "Tôi chỉ tìm thấy một sản phẩm. Không tìm thấy: '" . $missing . "'.";
    }
    
    // Pricing
    $formatPrice = function($p) use ($registry) {
        if ($registry->get('customer')->isLogged() || !$registry->get('config')->get('config_customer_price')) {
            $price_with_tax = $registry->get('tax')->calculate($p['price'], $p['tax_class_id'], $registry->get('config')->get('config_tax'));
            $base = $registry->get('currency')->format($price_with_tax, $registry->get('config')->get('config_currency'));
            if (!empty($p['special'])) {
                $special_with_tax = $registry->get('tax')->calculate($p['special'], $p['tax_class_id'], $registry->get('config')->get('config_tax'));
                return $base . " (KM: " . $registry->get('currency')->format($special_with_tax, $registry->get('config')->get('config_currency')) . ")";
            }
            return $base;
        }
        $base = $registry->get('currency')->format($p['price'], $registry->get('config')->get('config_currency'));
        if (!empty($p['special'])) {
            return $base . " (KM: " . $registry->get('currency')->format($p['special'], $registry->get('config')->get('config_currency')) . ")";
        }
        return $base;
    };
    
    $stockText = function($p) use ($registry) {
        if ($p['quantity'] <= 0) return "❌ Hết hàng";
        if (!$registry->get('config')->get('config_stock_display')) return "✅ Còn hàng";
        return "✅ Còn hàng: " . (int)$p['quantity'];
    };
    
    $url = function($p) use ($registry) {
        $path = getProductPath($p['product_id']);
        if ($path) {
            return html_entity_decode($registry->get('url')->link('product/category', 'language=' . $registry->get('config')->get('config_language') . '&path=' . $path . '&product_id=' . $p['product_id']));
        }
        return html_entity_decode($registry->get('url')->link('product/product', 'language=' . $registry->get('config')->get('config_language') . '&product_id=' . $p['product_id']));
    };
    
    $lines = [];
    $lines[] = "📊 So sánh sản phẩm:";
    $lines[] = "A: " . $a['name'] . "\nB: " . $b['name'];
    $lines[] = "💰 Giá A: " . $formatPrice($a);
    $lines[] = "💰 Giá B: " . $formatPrice($b);
    $lines[] = "📦 Tồn kho A: " . $stockText($a);
    $lines[] = "📦 Tồn kho B: " . $stockText($b);
    if (!empty($a['model']) || !empty($b['model'])) {
        $lines[] = "🔖 Model A: " . ($a['model'] ?? '') . "\n🔖 Model B: " . ($b['model'] ?? '');
    }
    $lines[] = "🔗 A: " . $url($a);
    $lines[] = "🔗 B: " . $url($b);
    $lines[] = "\nBạn có muốn thêm sản phẩm nào vào giỏ không?";
    
    return implode("\n", $lines);
}
?>
