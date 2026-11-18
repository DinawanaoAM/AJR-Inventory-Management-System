<?php
require_once 'includes/db.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$user_message = $input['message'] ?? '';

if (!is_string($user_message) || trim($user_message) === '') {
    echo json_encode(['response' => 'Please enter a message.']);
    exit;
}

$user_message = trim($user_message);

if (!$conn || $conn->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

// Convert user message to lowercase for easier matching
$message = strtolower($user_message);

// Get database statistics
$response = '';

// Total products
function fetchTotal(mysqli $conn, string $query): int
{
    $result = $conn->query($query);
    if (!$result) {
        throw new RuntimeException('Database query failed: ' . $conn->error);
    }

    return (int) $result->fetch_assoc()['total'];
}

try {
    $total_products = fetchTotal($conn, "SELECT COUNT(*) AS total FROM products");
    $total_stock = fetchTotal($conn, "SELECT COALESCE(SUM(quantity), 0) AS total FROM products");
    $out_of_stock = fetchTotal($conn, "SELECT COUNT(*) AS total FROM products WHERE quantity = 0");

    $top_selling_sql = "SELECT
        cr.product_name,
        SUM(cr.quantity) AS total_quantity,
        SUM(cr.total_amount) AS total_revenue
    FROM checkout_records cr
    GROUP BY cr.product_id, cr.product_name
    ORDER BY total_quantity DESC
    LIMIT 3";
    $top_selling_result = $conn->query($top_selling_sql);
    if (!$top_selling_result) {
        throw new RuntimeException('Database query failed: ' . $conn->error);
    }

    $top_selling = [];
    while ($row = $top_selling_result->fetch_assoc()) {
        $top_selling[] = [
            'product' => $row['product_name'],
            'quantity' => $row['total_quantity'],
            'amount' => $row['total_revenue']
        ];
    }

    $low_stock = fetchTotal($conn, "SELECT COUNT(*) AS total FROM products WHERE quantity < 10 AND quantity > 0");
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load inventory data.']);
    $conn->close();
    exit;
}

// Generate response based on user message
if (strpos($message, 'total product') !== false || strpos($message, 'how many product') !== false) {
    $response = "There are currently {$total_products} products in the inventory.";
} 
elseif (strpos($message, 'total stock') !== false || strpos($message, 'total quantity') !== false) {
    $response = "The total stock quantity across all products is {$total_stock} units.";
}
elseif (strpos($message, 'out of stock') !== false || strpos($message, 'no stock') !== false) {
    $response = "There are currently {$out_of_stock} products that are out of stock.";
}
elseif (strpos($message, 'top sell') !== false || strpos($message, 'best sell') !== false) {
    if (!empty($top_selling)) {
        $response = "The top selling products are:\n";
        foreach ($top_selling as $index => $product) {
            $response .= ($index + 1) . ". {$product['product']} - {$product['quantity']} units sold (₱" . number_format($product['amount'], 2) . ")\n";
        }
    } else {
        $response = "No sales data available yet.";
    }
}
elseif (strpos($message, 'low stock') !== false || strpos($message, 'running low') !== false) {
    $response = "There are {$low_stock} products with low stock (less than 10 units remaining).";
}
elseif (strpos($message, 'hello') !== false || strpos($message, 'hi') !== false) {
    $response = "Hello! I'm your inventory assistant. I can help you with information about:\n- Total products\n- Total stock levels\n- Out of stock items\n- Top selling products\n- Low stock alerts\n\nWhat would you like to know?";
}
else {
    $response = "I can help you with information about:\n- Total products\n- Total stock levels\n- Out of stock items\n- Top selling products\n- Low stock alerts\n\nPlease ask about any of these topics.";
}

$conn->close();
echo json_encode(['response' => $response]);