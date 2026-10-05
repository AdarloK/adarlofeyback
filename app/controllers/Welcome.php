<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends Controller {
	public function index() {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'name' => 'Product Management API',
			'status' => 'running',
			'version' => '1.0.0',
			'docs' => [
				'POST /api/auth/register',
				'POST /api/auth/login',
				'POST /api/auth/refresh',
				'POST /api/auth/logout',
				'GET /api/auth/me',
				'GET|POST /api/products',
				'GET|PUT|PATCH|DELETE /api/products/{id}',
			],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
?>