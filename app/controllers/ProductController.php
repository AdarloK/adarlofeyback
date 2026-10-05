<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * ProductController
 *
 * CRUD for the `products` table. Every endpoint requires a valid
 * "Authorization: Bearer <access_token>" header (checked by Api::require_jwt()).
 *
 *   GET    /api/products          list (optional ?search=)
 *   GET    /api/products/{id}     show one
 *   POST   /api/products          create
 *   PUT    /api/products/{id}     update
 *   PATCH  /api/products/{id}     update (partial)
 *   DELETE /api/products/{id}     delete
 */
class ProductController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        header('Content-Type: application/json; charset=utf-8');
        $this->call->library('api');
        $this->call->database();

        // Every product endpoint is protected.
        $this->api->require_jwt();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Cast DB strings to proper JSON types. */
    private function format(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'product_name' => $row['product_name'],
            'description'  => $row['description'],
            'price'        => round((float) $row['price'], 2),
            'quantity'     => (int) $row['quantity'],
            'created_at'   => $row['created_at'],
        ];
    }

    private function find(int $id): ?array
    {
        $row = $this->db->table('products')->where('id', $id)->get();
        return $row ?: null;
    }

    private function valid_id($id): int
    {
        if (!ctype_digit((string) $id) || (int) $id < 1) {
            $this->api->respond_error('Invalid product id.', 400);
        }
        return (int) $id;
    }

    /** Api::body() HTML-escapes strings; we store raw text (React escapes on output). */
    private function clean_text($value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Validate input. When $partial is TRUE only the supplied fields are checked.
     *
     * @return array [cleaned data, errors]
     */
    private function validate(array $in, bool $partial = false): array
    {
        $data   = [];
        $errors = [];

        if (!$partial || array_key_exists('product_name', $in)) {
            $name = $this->clean_text($in['product_name'] ?? '');
            if ($name === '') {
                $errors['product_name'] = 'Product name is required.';
            } elseif (mb_strlen($name) > 100) {
                $errors['product_name'] = 'Product name must be 100 characters or fewer.';
            } else {
                $data['product_name'] = $name;
            }
        }

        if (!$partial || array_key_exists('description', $in)) {
            $desc = $this->clean_text($in['description'] ?? '');
            if (mb_strlen($desc) > 5000) {
                $errors['description'] = 'Description is too long (max 5000 characters).';
            } else {
                $data['description'] = $desc === '' ? null : $desc;
            }
        }

        if (!$partial || array_key_exists('price', $in)) {
            $price = $in['price'] ?? null;
            if (!is_numeric($price) || (float) $price < 0 || (float) $price > 99999999.99) {
                $errors['price'] = 'Price must be a number between 0 and 99,999,999.99.';
            } else {
                $data['price'] = number_format((float) $price, 2, '.', '');
            }
        }

        if (!$partial || array_key_exists('quantity', $in)) {
            $qty = filter_var($in['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($qty === false || $qty < 0 || $qty > 2147483647) {
                $errors['quantity'] = 'Quantity must be a whole number (0 or more).';
            } else {
                $data['quantity'] = $qty;
            }
        }

        return [$data, $errors];
    }

    private function fail_validation(array $errors)
    {
        $this->api->respond([
            'status' => 422,
            'error'  => 'Validation failed',
            'errors' => $errors,
        ], 422);
    }

    // ------------------------------------------------------------------
    // GET /api/products
    // ------------------------------------------------------------------
    public function index()
    {
        $query  = $this->api->get_query_params();
        $search = trim((string) ($query['search'] ?? ''));

        try {
            if ($search !== '') {
                $like = '%' . html_entity_decode($search, ENT_QUOTES, 'UTF-8') . '%';
                $rows = $this->db->raw(
                    'SELECT * FROM products WHERE product_name LIKE ? OR description LIKE ? ORDER BY id DESC',
                    [$like, $like]
                )->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $this->db->table('products')->order_by('id', 'DESC')->get_all();
            }
        } catch (Throwable $e) {
            $this->api->respond_error('Could not load products.', 500);
        }

        $products = array_map([$this, 'format'], $rows ?: []);

        $this->api->respond([
            'status' => 'success',
            'count'  => count($products),
            'data'   => $products,
        ]);
    }

    // ------------------------------------------------------------------
    // GET /api/products/{id}
    // ------------------------------------------------------------------
    public function show($id)
    {
        $id  = $this->valid_id($id);
        $row = $this->find($id);

        if (!$row) {
            $this->api->respond_error('Product not found.', 404);
        }

        $this->api->respond(['status' => 'success', 'data' => $this->format($row)]);
    }

    // ------------------------------------------------------------------
    // POST /api/products
    // ------------------------------------------------------------------
    public function store()
    {
        [$data, $errors] = $this->validate($this->api->body());
        if ($errors) {
            $this->fail_validation($errors);
        }

        try {
            $new_id  = $this->db->table('products')->insert($data);
            $product = $this->find((int) $new_id);
        } catch (Throwable $e) {
            $this->api->respond_error('Could not create product.', 500);
        }

        $this->api->respond([
            'status'  => 'success',
            'message' => 'Product created.',
            'data'    => $this->format($product),
        ], 201);
    }

    // ------------------------------------------------------------------
    // PUT / PATCH /api/products/{id}
    // ------------------------------------------------------------------
    public function update($id)
    {
        $id = $this->valid_id($id);

        if (!$this->find($id)) {
            $this->api->respond_error('Product not found.', 404);
        }

        $input = $this->api->body();
        [$data, $errors] = $this->validate($input, $_SERVER['REQUEST_METHOD'] === 'PATCH');
        if ($errors) {
            $this->fail_validation($errors);
        }
        if (!$data) {
            $this->api->respond_error('Nothing to update.', 400);
        }

        try {
            $this->db->table('products')->where('id', $id)->update($data);
            $product = $this->find($id);
        } catch (Throwable $e) {
            $this->api->respond_error('Could not update product.', 500);
        }

        $this->api->respond([
            'status'  => 'success',
            'message' => 'Product updated.',
            'data'    => $this->format($product),
        ]);
    }

    // ------------------------------------------------------------------
    // DELETE /api/products/{id}
    // ------------------------------------------------------------------
    public function destroy($id)
    {
        $id = $this->valid_id($id);

        if (!$this->find($id)) {
            $this->api->respond_error('Product not found.', 404);
        }

        try {
            $this->db->table('products')->where('id', $id)->delete();
        } catch (Throwable $e) {
            $this->api->respond_error('Could not delete product.', 500);
        }

        $this->api->respond(['status' => 'success', 'message' => 'Product deleted.']);
    }
}
