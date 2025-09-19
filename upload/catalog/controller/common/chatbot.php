<?php
namespace Opencart\Catalog\Controller\Common;
/**
 * Class Chatbot
 *
 * Can be loaded using $this->load->controller('common/chatbot');
 *
 * @package Opencart\Catalog\Controller\Common
 */
class Chatbot extends \Opencart\System\Engine\Controller {
	/**
	 * Index - Main chatbot endpoint
	 *
	 * @return void
	 */
	public function index(): void {
		$this->load->language('api/chatbot');
		
		$json = [];
		// Suggestions endpoint via MVC route
		if (isset($this->request->get['suggest']) && (int)$this->request->get['suggest'] === 1) {
			$json['suggestions'] = $this->getQuickReplies();
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}
		
		if (isset($this->request->post['message'])) {
			$message = trim($this->request->post['message']);
			$json['response'] = $this->processMessage($message);
		} else {
			$json['error'] = 'No message provided';
		}
		
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
	
	/**
	 * Process user message and generate response
	 *
	 * @param string $message
	 * @return string
	 */
	private function processMessage(string $message): string {
		$original_message = $message;
		$message = strtolower(trim($message));
		
		// Category inquiry - check for exact matches first (PRIORITY)
		if ($message === 'danh mục' || $message === 'category' || $message === 'danh sách' || $message === 'danh mục sản phẩm') {
			return $this->showCategories();
		}
		
		// Check for category commands with additional text
		if (strpos($message, 'danh mục') !== false || strpos($message, 'category') !== false || strpos($message, 'loại') !== false) {
			// If it's "danh mục [name]" or "category [name]", show products in that category
			$category_name = str_replace(['danh mục', 'category', 'danh sách', 'loại', 'sản phẩm'], '', $message);
			$category_name = trim($category_name);
			if (!empty($category_name)) {
				return $this->showCategoryProducts($category_name);
			}
			return $this->showCategories();
		}
		
		// Check if message is a number (category ID)
		if (is_numeric($message)) {
			return $this->showCategoryProducts($original_message);
		}
		
		// Check if message might be a category name
		$categories = $this->getCategories();
		$search_term = $message;
		foreach ($categories as $category) {
			if (strpos(strtolower($category['name']), $search_term) !== false) {
				return $this->showCategoryProducts($original_message);
			}
		}
		
		// Product search (moved after category checks)
		if (strpos($message, 'tìm') !== false || strpos($message, 'search') !== false) {
			return $this->searchProducts($original_message);
		}
		
		// Price inquiry
		if (strpos($message, 'giá') !== false || strpos($message, 'price') !== false || strpos($message, 'cost') !== false) {
			return $this->getPriceInfo($original_message);
		}
		
		// Stock inquiry
		if (strpos($message, 'còn hàng') !== false || strpos($message, 'stock') !== false || strpos($message, 'tồn kho') !== false) {
			return $this->getStockInfo($original_message);
		}
		
		// General help
		if (strpos($message, 'giúp') !== false || strpos($message, 'help') !== false || strpos($message, 'hướng dẫn') !== false) {
			return $this->getHelp();
		}
		
		// Default response
		return "Xin chào! Tôi có thể giúp bạn tìm kiếm sản phẩm, kiểm tra giá, danh mục và tình trạng tồn kho. Bạn muốn biết gì?";
	}
	
	/**
	 * Get product path
	 *
	 * @param int $product_id
	 * @return string
	 */
	private function getProductPath(int $product_id): string {
		// Get the first category of the product
		$query = $this->db->query("SELECT p2c.category_id FROM `" . DB_PREFIX . "product_to_category` p2c WHERE p2c.product_id = '" . (int)$product_id . "' LIMIT 1");
		
		if (!$query->num_rows) {
			return '';
		}
		
		$category_id = $query->row['category_id'];
		
		// Get the path for this category
		$path_query = $this->db->query("SELECT GROUP_CONCAT(cp.path_id ORDER BY cp.level SEPARATOR '_') as path FROM `" . DB_PREFIX . "category_path` cp WHERE cp.category_id = '" . (int)$category_id . "'");
		
		if ($path_query->num_rows) {
			return $path_query->row['path'];
		}
		
		return $category_id; // Fallback to category_id if no path found
	}

	/**
	 * Get categories
	 *
	 * @return array
	 */
	private function getCategories(): array {
		$query = $this->db->query("SELECT c.category_id, cd.name, c.parent_id FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id) LEFT JOIN `" . DB_PREFIX . "category_to_store` c2s ON (c.category_id = c2s.category_id) WHERE cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND c.status = '1' AND c.parent_id = '0' ORDER BY c.sort_order, LCASE(cd.name)");
		
		return $query->rows;
	}

	/**
	 * Build data-driven quick replies
	 *
	 * @return array<int, array<string, string>>
	 */
	private function getQuickReplies(): array {
		$replies = [];
		
		// Top-level categories (first 3)
		$categories = $this->getCategories();
		foreach (array_slice($categories, 0, 3) as $category) {
			$replies[] = [
				'label' => '📁 ' . $category['name'],
				'message' => 'danh mục ' . $category['name']
			];
		}
		
		// Top viewed products (first 3)
		$this->load->model('catalog/product');
		$top = $this->model_catalog_product->getProducts([
			'sort' => 'p.viewed',
			'order' => 'DESC',
			'start' => 0,
			'limit' => 3
		]);
		foreach ($top as $product) {
			$replies[] = [
				'label' => '🔍 ' . $product['name'],
				'message' => 'tìm ' . $product['name']
			];
		}
		
		// Compare top two products if available
		if (count($top) >= 2) {
			$replies[] = [
				'label' => '📊 So sánh ' . $top[0]['name'] . ' vs ' . $top[1]['name'],
				'message' => 'so sánh ' . $top[0]['name'] . ' vs ' . $top[1]['name']
			];
		}
		
		// Help
		$replies[] = [
			'label' => '❓ Trợ giúp',
			'message' => 'giúp đỡ'
		];
		
		return $replies;
	}

	/**
	 * Get category products
	 *
	 * @param int $category_id
	 * @return array
	 */
	private function getCategoryProducts(int $category_id): array {
		$this->load->model('catalog/product');
		
		$data = [
			'filter_category_id' => $category_id,
			'filter_sub_category' => true,
			'start' => 0,
			'limit' => 10
		];
		
		return $this->model_catalog_product->getProducts($data);
	}

	/**
	 * Get category name
	 *
	 * @param int $category_id
	 * @return string
	 */
	private function getCategoryName(int $category_id): string {
		$query = $this->db->query("SELECT cd.name FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id) WHERE c.category_id = '" . (int)$category_id . "' AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "' AND c.status = '1'");
		
		return $query->num_rows ? $query->row['name'] : '';
	}

	/**
	 * Show categories
	 *
	 * @return string
	 */
	private function showCategories(): string {
		$categories = $this->getCategories();
		
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

	/**
	 * Show category products
	 *
	 * @param string $message
	 * @return string
	 */
	private function showCategoryProducts(string $message): string {
		// Extract category identifier from message
		$category_id = null;
		$category_name = '';
		
		// Check if message is a number (could be category ID or list index)
		if (is_numeric(trim($message))) {
			$number = (int)trim($message);
			// First, try as a real category ID
			$found_name = $this->getCategoryName($number);
			if ($found_name) {
				$category_id = $number;
				$category_name = $found_name;
			} else {
				// Fallback: treat as list index from showCategories()
				$categories = $this->getCategories();
				if ($number >= 1 && $number <= count($categories)) {
					$selected = $categories[$number - 1];
					$category_id = (int)$selected['category_id'];
					$category_name = $selected['name'];
				}
			}
		} else {
			// Search by category name
			$categories = $this->getCategories();
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
		
		$products = $this->getCategoryProducts($category_id);
		
		if (empty($products)) {
			return "📁 **Danh mục: " . $category_name . "**\n\n❌ Không có sản phẩm nào trong danh mục này.";
		}
		
		$response = "📁 **Danh mục: " . $category_name . "**\n";
		$response .= "📦 Tìm thấy " . count($products) . " sản phẩm:\n\n";
		
		foreach ($products as $product) {
			$response .= "🔹 " . $product['name'] . "\n";
			$response .= "💰 Giá: " . $this->currency->format($product['price'], $this->config->get('config_currency')) . "\n";
			$response .= "📦 Còn lại: " . $product['quantity'] . " sản phẩm\n";
			
			// Get product path and create correct URL
			$path = $this->getProductPath($product['product_id']);
			if ($path) {
				$response .= "🔗 Xem chi tiết: " . html_entity_decode($this->url->link('product/category', 'language=' . $this->config->get('config_language') . '&path=' . $path . '&product_id=' . $product['product_id'])) . "\n\n";
			} else {
				$response .= "🔗 Xem chi tiết: " . html_entity_decode($this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id'])) . "\n\n";
			}
		}
		
		return $response;
	}

	/**
	 * Search products based on message
	 *
	 * @param string $message
	 * @return string
	 */
	private function searchProducts(string $message): string {
		$this->load->model('catalog/product');
		
		// Extract search terms
		$search_terms = $this->extractSearchTerms($message);
		
		if (empty($search_terms)) {
			return "Bạn có thể cho tôi biết tên sản phẩm cụ thể để tìm kiếm không?";
		}
		
		$data = [
			'filter_search' => implode(' ', $search_terms),
			'limit' => 5
		];
		
		$products = $this->model_catalog_product->getProducts($data);
		
		if (empty($products)) {
			return "Xin lỗi, tôi không tìm thấy sản phẩm nào phù hợp với từ khóa '" . implode(' ', $search_terms) . "'. Bạn có thể thử từ khóa khác không?";
		}
		
		$response = "Tôi tìm thấy " . count($products) . " sản phẩm phù hợp:\n\n";
		
		foreach ($products as $product) {
			$response .= "🔹 " . $product['name'] . "\n";
			$response .= "💰 Giá: " . $this->currency->format($product['price'], $this->config->get('config_currency')) . "\n";
			$response .= "📦 Còn lại: " . $product['quantity'] . " sản phẩm\n";
			// Get product path and create correct URL
			$path = $this->getProductPath($product['product_id']);
			if ($path) {
				$response .= "🔗 Xem chi tiết: " . html_entity_decode($this->url->link('product/category', 'language=' . $this->config->get('config_language') . '&path=' . $path . '&product_id=' . $product['product_id'])) . "\n\n";
			} else {
				$response .= "🔗 Xem chi tiết: " . html_entity_decode($this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id'])) . "\n\n";
			}
		}
		
		return $response;
	}
	
	/**
	 * Get price information
	 *
	 * @param string $message
	 * @return string
	 */
	private function getPriceInfo(string $message): string {
		$this->load->model('catalog/product');
		
		$search_terms = $this->extractSearchTerms($message);
		
		if (empty($search_terms)) {
			return "Bạn muốn kiểm tra giá sản phẩm nào? Vui lòng cho tôi biết tên sản phẩm.";
		}
		
		$data = [
			'filter_search' => implode(' ', $search_terms),
			'limit' => 3
		];
		
		$products = $this->model_catalog_product->getProducts($data);
		
		if (empty($products)) {
			return "Không tìm thấy sản phẩm '" . implode(' ', $search_terms) . "' để kiểm tra giá.";
		}
		
		$response = "Thông tin giá sản phẩm:\n\n";
		
		foreach ($products as $product) {
			$response .= "🔹 " . $product['name'] . "\n";
			$response .= "💰 Giá: " . $this->currency->format($product['price'], $this->config->get('config_currency')) . "\n";
			
			if ($product['special']) {
				$response .= "🎯 Giá khuyến mãi: " . $this->currency->format($product['special'], $this->config->get('config_currency')) . "\n";
			}
			
			$response .= "\n";
		}
		
		return $response;
	}
	
	/**
	 * Get categories
	 *
	 * @param string $message
	 * @return string
	 */
	private function getCategories(string $message): string {
		$this->load->model('catalog/category');
		
		$categories = $this->model_catalog_category->getCategories();
		
		if (empty($categories)) {
			return "Hiện tại chưa có danh mục sản phẩm nào.";
		}
		
		$response = "Danh mục sản phẩm hiện có:\n\n";
		
		foreach (array_slice($categories, 0, 10) as $category) {
			$response .= "📁 " . $category['name'] . "\n";
		}
		
		if (count($categories) > 10) {
			$response .= "\n... và " . (count($categories) - 10) . " danh mục khác";
		}
		
		return $response;
	}
	
	/**
	 * Get stock information
	 *
	 * @param string $message
	 * @return string
	 */
	private function getStockInfo(string $message): string {
		$this->load->model('catalog/product');
		
		$search_terms = $this->extractSearchTerms($message);
		
		if (empty($search_terms)) {
			return "Bạn muốn kiểm tra tình trạng tồn kho sản phẩm nào?";
		}
		
		$data = [
			'filter_search' => implode(' ', $search_terms),
			'limit' => 5
		];
		
		$products = $this->model_catalog_product->getProducts($data);
		
		if (empty($products)) {
			return "Không tìm thấy sản phẩm '" . implode(' ', $search_terms) . "' để kiểm tra tồn kho.";
		}
		
		$response = "Tình trạng tồn kho:\n\n";
		
		foreach ($products as $product) {
			$response .= "🔹 " . $product['name'] . "\n";
			
			if ($product['quantity'] > 0) {
				$response .= "✅ Còn hàng: " . $product['quantity'] . " sản phẩm\n";
			} else {
				$response .= "❌ Hết hàng\n";
			}
			
			$response .= "\n";
		}
		
		return $response;
	}
	
	/**
	 * Get help information
	 *
	 * @return string
	 */
	private function getHelp(): string {
		return "🤖 Tôi có thể giúp bạn:\n\n" .
			   "🔍 Tìm kiếm sản phẩm: 'tìm [tên sản phẩm]'\n" .
			   "💰 Kiểm tra giá: 'giá [tên sản phẩm]'\n" .
			   "📁 Xem danh mục: 'danh mục sản phẩm'\n" .
			   "📦 Kiểm tra tồn kho: 'còn hàng [tên sản phẩm]'\n" .
			   "❓ Hướng dẫn: 'giúp đỡ'\n\n" .
			   "Bạn muốn tìm hiểu gì?";
	}
	
	/**
	 * Extract search terms from message
	 *
	 * @param string $message
	 * @return array
	 */
	private function extractSearchTerms(string $message): array {
		// Remove common words
		$stop_words = ['tìm', 'search', 'sản phẩm', 'product', 'giá', 'price', 'còn', 'hàng', 'stock', 'tồn', 'kho'];
		
		$words = explode(' ', $message);
		$terms = [];
		
		foreach ($words as $word) {
			$word = trim($word);
			if (!empty($word) && !in_array($word, $stop_words)) {
				$terms[] = $word;
			}
		}
		
		return $terms;
	}
}
