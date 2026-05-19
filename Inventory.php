<?php
require_once 'config.php';

class Inventory {
    private $host;
    private $user;
    private $password;
    private $database;
    private $userTable = 'ims_user';	
    private $customerTable = 'ims_customer';
    private $categoryTable = 'ims_category';
    private $brandTable = 'ims_brand';
    private $productTable = 'ims_product';
    private $supplierTable = 'ims_supplier';
    private $purchaseTable = 'ims_purchase';
    private $orderTable = 'ims_order';
    private $dbConnect = null;

    public function __construct() {
        $this->host = DB_HOST;
        $this->user = DB_USER;
        $this->password = DB_PASS;
        $this->database = DB_NAME;
        
        $this->connectDatabase();
    }

    private function connectDatabase() {
        try {
            $this->dbConnect = new mysqli($this->host, $this->user, $this->password, $this->database);
            
            if ($this->dbConnect->connect_error) {
                throw new Exception('Database connection failed');
            }
            
            $this->dbConnect->set_charset("utf8mb4");
        } catch (Exception $e) {
            error_log('Database Error: ' . $e->getMessage());
            sendJsonResponse(['error' => 'Database connection failed'], 500);
        }
    }
	private function getData($sqlQuery) {
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		if(!$result){
			die('Error in query: '. mysqli_error());
		}
		$data= array();
		while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
			$data[]=$row;            
		}
		return $data;
	}
	private function getNumRows($sqlQuery) {
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		if(!$result){
			die('Error in query: '. mysqli_error());
		}
		$numRows = mysqli_num_rows($result);
		return $numRows;
	}
	private function escapeLikeValue($value) {
		return $this->dbConnect->real_escape_string($value);
	}
	private function getOrderByClause($orderRequest, $allowedColumns, $defaultOrderBy) {
		if (!empty($orderRequest) && isset($orderRequest[0]['column'], $orderRequest[0]['dir'])) {
			$columnIndex = (int)$orderRequest[0]['column'];
			$direction = strtolower($orderRequest[0]['dir']) === 'asc' ? 'ASC' : 'DESC';
			if (isset($allowedColumns[$columnIndex])) {
				return ' ORDER BY '.$allowedColumns[$columnIndex].' '.$direction.' ';
			}
		}
		return ' ORDER BY '.$defaultOrderBy.' ';
	}
	private function getLimitClause() {
		if (isset($_POST['length']) && (int)$_POST['length'] !== -1) {
			$start = isset($_POST['start']) ? max(0, (int)$_POST['start']) : 0;
			$length = max(0, (int)$_POST['length']);
			return ' LIMIT '.$start.', '.$length;
		}
		return '';
	}
	private function getPositiveInt($value) {
		$intValue = (int)$value;
		return $intValue > 0 ? $intValue : 0;
	}
	private function calculateInventoryInHand($productId, $excludeOrderId = 0) {
		$productId = (int)$productId;
		$excludeOrderId = (int)$excludeOrderId;
		if ($productId <= 0) {
			return -1;
		}

		$productStmt = $this->dbConnect->prepare("SELECT quantity FROM ".$this->productTable." WHERE pid = ? LIMIT 1");
		$productStmt->bind_param("i", $productId);
		$productStmt->execute();
		$productResult = $productStmt->get_result();
		$product = $productResult->fetch_assoc();
		$productStmt->close();
		if (!$product) {
			return -1;
		}

		$purchaseTotal = 0;
		$purchaseStmt = $this->dbConnect->prepare("SELECT COALESCE(SUM(quantity), 0) as qty FROM ".$this->purchaseTable." WHERE product_id = ?");
		$purchaseStmt->bind_param("i", $productId);
		$purchaseStmt->execute();
		$purchaseResult = $purchaseStmt->get_result()->fetch_assoc();
		$purchaseStmt->close();
		if ($purchaseResult) {
			$purchaseTotal = (int)$purchaseResult['qty'];
		}

		if ($excludeOrderId > 0) {
			$orderStmt = $this->dbConnect->prepare("SELECT COALESCE(SUM(total_shipped), 0) as qty FROM ".$this->orderTable." WHERE product_id = ? AND order_id != ?");
			$orderStmt->bind_param("ii", $productId, $excludeOrderId);
		} else {
			$orderStmt = $this->dbConnect->prepare("SELECT COALESCE(SUM(total_shipped), 0) as qty FROM ".$this->orderTable." WHERE product_id = ?");
			$orderStmt->bind_param("i", $productId);
		}
		$orderStmt->execute();
		$orderResult = $orderStmt->get_result()->fetch_assoc();
		$orderStmt->close();
		$orderTotal = $orderResult ? (int)$orderResult['qty'] : 0;

		return ((int)$product['quantity'] + $purchaseTotal) - $orderTotal;
	}
	public function login($email, $password){
		$sqlQuery = "
			SELECT userid, email, password, name, type, status
			FROM ".$this->userTable." 
			WHERE email = ? LIMIT 1";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$stmt->bind_param("s", $email);
		$stmt->execute();
		$result = $stmt->get_result();
		$user = $result->fetch_assoc();
		$stmt->close();
		if(!$user) {
			return array();
		}
		if(strtolower($user['status']) !== 'active') {
			return array();
		}

		$isLegacyMd5 = strlen($user['password']) === 32 && ctype_xdigit($user['password']);
		$isValidPassword = false;
		if($isLegacyMd5) {
			$isValidPassword = hash_equals($user['password'], md5($password));
			if($isValidPassword) {
				$newHash = password_hash($password, PASSWORD_DEFAULT);
				$updateStmt = $this->dbConnect->prepare("UPDATE ".$this->userTable." SET password = ? WHERE userid = ?");
				$updateStmt->bind_param("si", $newHash, $user['userid']);
				$updateStmt->execute();
				$updateStmt->close();
				$user['password'] = $newHash;
			}
		} else {
			$isValidPassword = password_verify($password, $user['password']);
		}

		return $isValidPassword ? array($user) : array();
	}	
	public function checkLogin() {
		if (empty($_SESSION['userid'])) {
			redirect('login.php');
		}
	}
	public function getCustomer() {
		$customerId = validatePositiveInt($_POST["userid"] ?? 0);
		if ($customerId === false) {
			sendJsonResponse(['error' => 'Invalid customer ID'], 400);
		}

		$sqlQuery = "SELECT * FROM " . $this->customerTable . " WHERE id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$stmt->bind_param("i", $customerId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		
		sendJsonResponse($row ?: ['error' => 'Customer not found'], $row ? 200 : 404);
	}
	
	public function getCustomerList() {		
		try {
			$sqlQuery = "SELECT id, name, address, mobile, balance FROM " . $this->customerTable;
			if (!empty($_POST["search"]["value"])) {
				$searchValue = $this->escapeLikeValue($_POST["search"]["value"]);
				$sqlQuery .= ' WHERE id LIKE "%' . $searchValue . '%" OR name LIKE "%' . $searchValue . '%" OR address LIKE "%' . $searchValue . '%" OR mobile LIKE "%' . $searchValue . '%" OR balance LIKE "%' . $searchValue . '%" ';
			}
			$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
				0 => 'id',
				1 => 'name',
				2 => 'address',
				3 => 'mobile',
				4 => 'balance'
			), 'id DESC');
			$sqlQuery .= $this->getLimitClause();
			
			$result = mysqli_query($this->dbConnect, $sqlQuery);
			if (!$result) {
				throw new Exception('Database query failed');
			}
			
			$numRows = mysqli_num_rows($result);
			$customerData = array();	
			
			while ($customer = mysqli_fetch_assoc($result)) {		
				$customerId = htmlspecialchars($customer['id'], ENT_QUOTES, 'UTF-8');
				$customerRows = array();
				$customerRows[] = $customer['id'];
				$customerRows[] = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
				$customerRows[] = htmlspecialchars($customer['address'], ENT_QUOTES, 'UTF-8');			
				$customerRows[] = htmlspecialchars($customer['mobile'], ENT_QUOTES, 'UTF-8');	
				$customerRows[] = number_format($customer['balance'], 2);	
				$customerRows[] = '<button type="button" name="update" id="' . $customerId . '" class="btn btn-primary btn-sm rounded-0 update" title="update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="' . $customerId . '" class="btn btn-danger btn-sm rounded-0 delete"><i class="fa fa-trash"></i></button>';
				$customerRows[] = '';
				$customerData[] = $customerRows;
			}
			
			$output = array(
				"draw" => intval($_POST["draw"] ?? 0),
				"recordsTotal" => $numRows,
				"recordsFiltered" => $numRows,
				"data" => $customerData
			);
			
			sendJsonResponse($output);
		} catch (Exception $e) {
			error_log('Customer List Error: ' . $e->getMessage());
			sendJsonResponse(['error' => 'Failed to retrieve customers'], 500);
		}
	}

	public function saveCustomer() {
		try {
			// Validate input
			$name = trim($_POST['cname'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$mobile = trim($_POST['mobile'] ?? '');
			$balance = validateFloat($_POST['balance'] ?? 0);

			if (empty($name) || strlen($name) > 255) {
				sendJsonResponse(['error' => 'Invalid customer name'], 400);
			}
			if (empty($address) || strlen($address) > 500) {
				sendJsonResponse(['error' => 'Invalid address'], 400);
			}
			if (empty($mobile) || !preg_match('/^[0-9\-\+\s()]{7,20}$/', $mobile)) {
				sendJsonResponse(['error' => 'Invalid mobile number'], 400);
			}
			if ($balance === false || $balance < 0) {
				sendJsonResponse(['error' => 'Invalid balance amount'], 400);
			}

			$sqlInsert = "INSERT INTO " . $this->customerTable . "(name, address, mobile, balance) VALUES (?, ?, ?, ?)";
			$stmt = $this->dbConnect->prepare($sqlInsert);
			if (!$stmt) {
				throw new Exception('Prepare failed: ' . $this->dbConnect->error);
			}
			
			$stmt->bind_param("sssd", $name, $address, $mobile, $balance);
			if (!$stmt->execute()) {
				throw new Exception('Execute failed: ' . $stmt->error);
			}
			$stmt->close();
			
			sendJsonResponse(['message' => 'New Customer Added'], 200);
		} catch (Exception $e) {
			error_log('Save Customer Error: ' . $e->getMessage());
			sendJsonResponse(['error' => 'Failed to save customer'], 500);
		}
	}			
	public function updateCustomer() {
		try {
			$customerId = validatePositiveInt($_POST['userid'] ?? 0);
			if ($customerId === false) {
				sendJsonResponse(['error' => 'Invalid customer ID'], 400);
			}

			// Validate input
			$name = trim($_POST['cname'] ?? '');
			$address = trim($_POST['address'] ?? '');
			$mobile = trim($_POST['mobile'] ?? '');
			$balance = validateFloat($_POST['balance'] ?? 0);

			if (empty($name) || strlen($name) > 255) {
				sendJsonResponse(['error' => 'Invalid customer name'], 400);
			}
			if (empty($address) || strlen($address) > 500) {
				sendJsonResponse(['error' => 'Invalid address'], 400);
			}
			if (empty($mobile) || !preg_match('/^[0-9\-\+\s()]{7,20}$/', $mobile)) {
				sendJsonResponse(['error' => 'Invalid mobile number'], 400);
			}
			if ($balance === false || $balance < 0) {
				sendJsonResponse(['error' => 'Invalid balance amount'], 400);
			}

			$sqlInsert = "UPDATE " . $this->customerTable . " SET name = ?, address = ?, mobile = ?, balance = ? WHERE id = ?";
			$stmt = $this->dbConnect->prepare($sqlInsert);
			if (!$stmt) {
				throw new Exception('Prepare failed: ' . $this->dbConnect->error);
			}
			
			$stmt->bind_param("sssdi", $name, $address, $mobile, $balance, $customerId);
			if (!$stmt->execute()) {
				throw new Exception('Execute failed: ' . $stmt->error);
			}
			$stmt->close();
			
			sendJsonResponse(['message' => 'Customer Updated'], 200);
		} catch (Exception $e) {
			error_log('Update Customer Error: ' . $e->getMessage());
			sendJsonResponse(['error' => 'Failed to update customer'], 500);
		}
	}	
	public function deleteCustomer(){
		$sqlQuery = "DELETE FROM ".$this->customerTable." WHERE id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$customerId = (int)$_POST['userid'];
		$stmt->bind_param("i", $customerId);
		$stmt->execute();
		$stmt->close();
	}
	// Category functions
	public function getCategoryList(){		
		$sqlQuery = "SELECT * FROM ".$this->categoryTable;
		if(!empty($_POST["search"]["value"])){
			$searchValue = $this->escapeLikeValue($_POST["search"]["value"]);
			$sqlQuery .= ' WHERE name LIKE "%'.$searchValue.'%" OR status LIKE "%'.$searchValue.'%" ';
		}
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'categoryid',
			1 => 'name',
			2 => 'status'
		), 'categoryid DESC');
		$sqlQuery .= $this->getLimitClause();
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$categoryData = array();	
		while( $category = mysqli_fetch_assoc($result) ) {		
			$categoryRows = array();
			$status = '';
			if($category['status'] == 'active')	{
				$status = '<span class="label label-success">Active</span>';
			} else {
				$status = '<span class="label label-danger">Inactive</span>';
			}
			$categoryRows[] = $category['categoryid'];
			$categoryRows[] = $category['name'];
			$categoryRows[] = $status;			
			$categoryRows[] = '<button type="button" name="update" id="'.$category["categoryid"].'" class="btn btn-primary btn-sm rounded-0 update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$category["categoryid"].'" class="btn btn-danger btn-sm rounded-0 delete"  title="Delete"><i class="fa fa-trash"></i></button>';
			$categoryData[] = $categoryRows;
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$categoryData
		);
		echo json_encode($output);
	}
	public function saveCategory() {		
		$sqlInsert = "INSERT INTO ".$this->categoryTable."(name) VALUES (?)";
		$stmt = $this->dbConnect->prepare($sqlInsert);
		$categoryName = trim($_POST['category']);
		$stmt->bind_param("s", $categoryName);
		$stmt->execute();
		$stmt->close();
		echo 'New Category Added';
	}	
	public function getCategory(){
		$sqlQuery = "SELECT * FROM ".$this->categoryTable." WHERE categoryid = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$categoryId = (int)$_POST["categoryId"];
		$stmt->bind_param("i", $categoryId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		echo json_encode($row);
	}
	public function updateCategory() {
		if($_POST['category']) {	
			$sqlInsert = "UPDATE ".$this->categoryTable." SET name = ? WHERE categoryid = ?";
			$stmt = $this->dbConnect->prepare($sqlInsert);
			$categoryName = trim($_POST['category']);
			$categoryId = (int)$_POST["categoryId"];
			$stmt->bind_param("si", $categoryName, $categoryId);
			$stmt->execute();
			$stmt->close();
			echo 'Category Update';
		}	
	}	
	public function deleteCategory(){
		$sqlQuery = "DELETE FROM ".$this->categoryTable." WHERE categoryid = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$categoryId = (int)$_POST["categoryId"];
		$stmt->bind_param("i", $categoryId);
		$stmt->execute();
		$stmt->close();
	}
	// Brand management 
	public function getBrandList(){				
		$sqlQuery = "SELECT * FROM ".$this->brandTable." as b 
			INNER JOIN ".$this->categoryTable." as c ON c.categoryid = b.categoryid ";
		if(!empty($_POST["search"]["value"])){
			$searchValue = $this->escapeLikeValue($_POST["search"]["value"]);
			$sqlQuery .= 'WHERE b.bname LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR c.name LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR b.status LIKE "%'.$searchValue.'%" ';		
		}
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'b.id',
			1 => 'b.bname',
			2 => 'c.name',
			3 => 'b.status'
		), 'b.id DESC');
		$sqlQuery .= $this->getLimitClause();
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$brandData = array();	
		while( $brand = mysqli_fetch_assoc($result) ) {			
			$status = '';
			if($brand['status'] == 'active')	{
				$status = '<span class="label label-success">Active</span>';
			} else {
				$status = '<span class="label label-danger">Inactive</span>';
			}
			$brandRows = array();
			$brandRows[] = $brand['id'];
			$brandRows[] = $brand['bname'];
			$brandRows[] = $brand['name'];
			$brandRows[] = $status;
			$brandRows[] = '<button type="button" name="update" id="'.$brand["id"].'" class="btn btn-primary btn-sm rounded-0  update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$brand["id"].'" class="btn btn-danger btn-sm rounded-0  delete" data-status="'.$brand["status"].'" title="Delete"><i class="fa fa-trash"></i></button>';
			$brandData[] = $brandRows;
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$brandData
		);
		echo json_encode($output);
	}
	public function categoryDropdownList(){		
		$sqlQuery = "SELECT * FROM ".$this->categoryTable." 
			WHERE status = 'active' 
			ORDER BY name ASC";	
		$result = mysqli_query($this->dbConnect, $sqlQuery);	
		$categoryHTML = '';
		while( $category = mysqli_fetch_assoc($result)) {
			$categoryHTML .= '<option value="' . htmlspecialchars($category["categoryid"], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($category["name"], ENT_QUOTES, 'UTF-8') . '</option>';	
		}
		return $categoryHTML;
	}
	public function saveBrand() {		
		$sqlInsert = "INSERT INTO ".$this->brandTable."(categoryid, bname) VALUES (?, ?)";
		$stmt = $this->dbConnect->prepare($sqlInsert);
		$categoryId = (int)$_POST["categoryid"];
		$brandName = trim($_POST['bname']);
		$stmt->bind_param("is", $categoryId, $brandName);
		$stmt->execute();
		$stmt->close();
		echo 'New Brand Added';
	}	
	public function getBrand(){
		$sqlQuery = "SELECT * FROM ".$this->brandTable." WHERE id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$brandId = (int)$_POST["id"];
		$stmt->bind_param("i", $brandId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		echo json_encode($row);
	}	
	public function updateBrand() {		
		if($_POST['id']) {	
			$sqlUpdate = "UPDATE ".$this->brandTable." SET bname = ?, categoryid = ? WHERE id = ?";
			$stmt = $this->dbConnect->prepare($sqlUpdate);
			$brandName = trim($_POST['bname']);
			$categoryId = (int)$_POST['categoryid'];
			$brandId = (int)$_POST["id"];
			$stmt->bind_param("sii", $brandName, $categoryId, $brandId);
			$stmt->execute();
			$stmt->close();
			echo 'Brand Update';
		}	
	}	
	public function deleteBrand(){
		$sqlQuery = "DELETE FROM ".$this->brandTable." WHERE id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$brandId = (int)$_POST["id"];
		$stmt->bind_param("i", $brandId);
		$stmt->execute();
		$stmt->close();
	}
	// Product management 
	public function getProductList(){				
		$sqlQuery = "SELECT * FROM ".$this->productTable." as p
			INNER JOIN ".$this->brandTable." as b ON b.id = p.brandid
			INNER JOIN ".$this->categoryTable." as c ON c.categoryid = p.categoryid 
			INNER JOIN ".$this->supplierTable." as s ON s.supplier_id = p.supplier ";
		if(isset($_POST["search"]["value"])) {
			$searchValue = $this->escapeLikeValue($_POST["search"]["value"]);
			$sqlQuery .= 'WHERE b.bname LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR c.name LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR p.pname LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR p.quantity LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR s.supplier_name LIKE "%'.$searchValue.'%" ';
			$sqlQuery .= 'OR p.pid LIKE "%'.$searchValue.'%" ';
		}
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'p.pid',
			1 => 'c.name',
			2 => 'b.bname',
			3 => 'p.pname',
			4 => 'p.model',
			5 => 'p.quantity',
			6 => 's.supplier_name',
			7 => 'p.status'
		), 'p.pid DESC');
		$sqlQuery .= $this->getLimitClause();		
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$productData = array();	
		while( $product = mysqli_fetch_assoc($result) ) {			
			$status = '';
			if($product['status'] == 'active') {
				$status = '<span class="label label-success">Active</span>';
			} else {
				$status = '<span class="label label-danger">Inactive</span>';
			}
			$productRow = array();
			$productRow[] = $product['pid'];
			$productRow[] = $product['name'];
			$productRow[] = $product['bname'];
			$productRow[] = $product['pname'];	
			$productRow[] = $product['model'];			
			$productRow[] = $product["quantity"];
			$productRow[] = $product['supplier_name'];
			$productRow[] = $status;
			$productRow[] = '<div class="btn-group btn-group-sm"><button type="button" name="view" id="'.$product["pid"].'" class="btn btn-light bg-gradient border text-dark btn-sm rounded-0  view" title="View"><i class="fa fa-eye"></i></button><button type="button" name="update" id="'.$product["pid"].'" class="btn btn-primary btn-sm rounded-0  update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$product["pid"].'" class="btn btn-danger btn-sm rounded-0  delete" data-status="'.$product["status"].'" title="Delete"><i class="fa fa-trash"></i></button></div>';
			$productData[] = $productRow;
						
		}
		$outputData = array(
			"draw"    			=> 	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$productData
		);
		echo json_encode($outputData);
	}
	public function getCategoryBrand($categoryid){	
		$sqlQuery = "SELECT * FROM ".$this->brandTable." WHERE status = 'active' AND categoryid = ? ORDER BY bname ASC";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$categoryId = (int)$categoryid;
		$stmt->bind_param("i", $categoryId);
		$stmt->execute();
		$result = $stmt->get_result();
		$dropdownHTML = '';
		while( $brand = mysqli_fetch_assoc($result) ) {	
			$dropdownHTML .= '<option value="' . htmlspecialchars($brand["id"], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($brand["bname"], ENT_QUOTES, 'UTF-8') . '</option>';
		}
		$stmt->close();
		return $dropdownHTML;
	}
	public function supplierDropdownList(){	
		$sqlQuery = "SELECT * FROM ".$this->supplierTable." 
			WHERE status = 'active'	ORDER BY supplier_name ASC";
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$dropdownHTML = '';
		while( $supplier = mysqli_fetch_assoc($result) ) {	
			$dropdownHTML .= '<option value="' . htmlspecialchars($supplier["supplier_id"], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supplier["supplier_name"], ENT_QUOTES, 'UTF-8') . '</option>';
		}
		return $dropdownHTML;
	}
	public function addProduct() {		
		$categoryId = (int)$_POST["categoryid"];
		$brandId = (int)$_POST['brandid'];
		$productName = trim($_POST['pname']);
		$productModel = trim($_POST['pmodel']);
		$description = trim($_POST['description']);
		$quantity = (int)$_POST['quantity'];
		$unit = trim($_POST['unit']);
		$basePrice = (float)$_POST['base_price'];
		$tax = (float)$_POST['tax'];
		$minimumOrder = 1.0;
		$supplierId = (int)$_POST['supplierid'];
		if ($categoryId <= 0 || $brandId <= 0 || $supplierId <= 0 || $quantity < 0 || $basePrice < 0 || $tax < 0 || $tax > 100 || $productName === '' || $unit === '') {
			echo 'Invalid product input';
			return;
		}
		$sqlInsert = "INSERT INTO ".$this->productTable."(categoryid, brandid, pname, model, description, quantity, unit, base_price, tax, minimum_order, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
		$stmt = $this->dbConnect->prepare($sqlInsert);
		$stmt->bind_param("iisssisdidi", $categoryId, $brandId, $productName, $productModel, $description, $quantity, $unit, $basePrice, $tax, $minimumOrder, $supplierId);
		$stmt->execute();
		$stmt->close();
		echo 'New Product Added';
	}	
	public function getProductDetails(){
		$sqlQuery = "SELECT * FROM ".$this->productTable." WHERE pid = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$productId = (int)$_POST["pid"];
		$stmt->bind_param("i", $productId);
		$stmt->execute();
		$result = $stmt->get_result();
		$product = $result->fetch_assoc();
		$stmt->close();
		$output = array();
		if($product) {
			$output['pid'] = $product['pid'];
			$output['categoryid'] = $product['categoryid'];
			$output['brandid'] = $product['brandid'];
			$output["brand_select_box"] = $this->getCategoryBrand($product['categoryid']);
			$output['pname'] = $product['pname'];
			$output['model'] = $product['model'];
			$output['description'] = $product['description'];
			$output['quantity'] = $product['quantity'];
			$output['unit'] = $product['unit'];
			$output['base_price'] = $product['base_price'];
			$output['tax'] = $product['tax'];
			$output['supplier'] = $product['supplier'];
		}
		echo json_encode($output);
	}
	public function updateProduct() {		
		if($_POST['pid']) {	
			$categoryId = (int)$_POST['categoryid'];
			$brandId = (int)$_POST['brandid'];
			$productName = trim($_POST['pname']);
			$productModel = trim($_POST['pmodel']);
			$description = trim($_POST['description']);
			$quantity = (int)$_POST['quantity'];
			$unit = trim($_POST['unit']);
			$basePrice = (float)$_POST['base_price'];
			$tax = (float)$_POST['tax'];
			$supplierId = (int)$_POST['supplierid'];
			$productId = (int)$_POST["pid"];
			if ($productId <= 0 || $categoryId <= 0 || $brandId <= 0 || $supplierId <= 0 || $quantity < 0 || $basePrice < 0 || $tax < 0 || $tax > 100 || $productName === '' || $unit === '') {
				echo 'Invalid product input';
				return;
			}
			$sqlUpdate = "UPDATE ".$this->productTable." SET categoryid = ?, brandid = ?, pname = ?, model = ?, description = ?, quantity = ?, unit = ?, base_price = ?, tax = ?, supplier = ? WHERE pid = ?";
			$stmt = $this->dbConnect->prepare($sqlUpdate);
			$stmt->bind_param("iisssisdidi", $categoryId, $brandId, $productName, $productModel, $description, $quantity, $unit, $basePrice, $tax, $supplierId, $productId);
			$stmt->execute();
			$stmt->close();
			echo 'Product Update';
		}	
	}	
	public function deleteProduct(){
		$sqlQuery = "DELETE FROM ".$this->productTable." WHERE pid = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$productId = (int)$_POST["pid"];
		$stmt->bind_param("i", $productId);
		$stmt->execute();
		$stmt->close();
	}	
	public function viewProductDetails(){
		$sqlQuery = "SELECT * FROM ".$this->productTable." as p
			INNER JOIN ".$this->brandTable." as b ON b.id = p.brandid
			INNER JOIN ".$this->categoryTable." as c ON c.categoryid = p.categoryid 
			INNER JOIN ".$this->supplierTable." as s ON s.supplier_id = p.supplier 
			WHERE p.pid = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$productId = (int)$_POST["pid"];
		$stmt->bind_param("i", $productId);
		$stmt->execute();
		$result = $stmt->get_result();
		$productDetails = '<div class="table-responsive">
				<table class="table table-boredered">';
		while( $product = mysqli_fetch_assoc($result) ) {
			$status = '';
			if($product['status'] == 'active') {
				$status = '<span class="label label-success">Active</span>';
			} else {
				$status = '<span class="label label-danger">Inactive</span>';
			}
			$productDetails .= '
			<tr>
				<td>Product Name</td>
				<td>'.$product["pname"].'</td>
			</tr>
			<tr>
				<td>Product Model</td>
				<td>'.$product["model"].'</td>
			</tr>
			<tr>
				<td>Product Description</td>
				<td>'.$product["description"].'</td>
			</tr>
			<tr>
				<td>Category</td>
				<td>'.$product["name"].'</td>
			</tr>
			<tr>
				<td>Brand</td>
				<td>'.$product["bname"].'</td>
			</tr>			
			<tr>
				<td>Available Quantity</td>
				<td>'.$product["quantity"].' '.$product["unit"].'</td>
			</tr>
			<tr>
				<td>Base Price</td>
				<td>'.$product["base_price"].'</td>
			</tr>
			<tr>
				<td>Tax (%)</td>
				<td>'.$product["tax"].'</td>
			</tr>
			<tr>
				<td>Enter By</td>
				<td>'.$product["supplier_name"].'</td>
			</tr>
			<tr>
				<td>Status</td>
				<td>'.$status.'</td>
			</tr>
			';
		}
		$productDetails .= '
			</table>
		</div>
		';
		$stmt->close();
		echo $productDetails;
	}
	// supplier 
	public function getSupplierList(){		
		$sqlQuery = "SELECT * FROM ".$this->supplierTable;
		if(!empty($_POST["search"]["value"])){
			$searchValue = $this->escapeLikeValue($_POST["search"]["value"]);
			$sqlQuery .= ' WHERE supplier_name LIKE "%'.$searchValue.'%" OR mobile LIKE "%'.$searchValue.'%" OR address LIKE "%'.$searchValue.'%" OR status LIKE "%'.$searchValue.'%" ';			
		}
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'supplier_id',
			1 => 'supplier_name',
			2 => 'mobile',
			3 => 'address',
			4 => 'status'
		), 'supplier_id DESC');
		$sqlQuery .= $this->getLimitClause();
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$supplierData = array();	
		while( $supplier = mysqli_fetch_assoc($result) ) {	
			$status = '';
			if($supplier['status'] == 'active') {
				$status = '<span class="label label-success">Active</span>';
			} else {
				$status = '<span class="label label-danger">Inactive</span>';
			}
			$supplierRows = array();
			$supplierRows[] = $supplier['supplier_id'];		
			$supplierRows[] = $supplier['supplier_name'];	
			$supplierRows[] = $supplier['mobile'];			
			$supplierRows[] = $supplier['address'];	
			$supplierRows[] = $status;			
			$supplierRows[] = '<div class="btn-group btn-group-sm"><button type="button" name="update" id="'.$supplier["supplier_id"].'" class="btn btn-primary btn-sm rounded-0  update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$supplier["supplier_id"].'" class="btn btn-danger btn-sm rounded-0  delete"  title="Delete"><i class="fa fa-trash"></i></button></div>';
			$supplierData[] = $supplierRows;
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$supplierData
		);
		echo json_encode($output);
	}
	public function addSupplier() {		
		$sqlInsert = "INSERT INTO ".$this->supplierTable."(supplier_name, mobile, address) VALUES (?, ?, ?)";
		$stmt = $this->dbConnect->prepare($sqlInsert);
		$supplierName = trim($_POST['supplier_name']);
		$mobile = trim($_POST['mobile']);
		$address = trim($_POST['address']);
		$stmt->bind_param("sss", $supplierName, $mobile, $address);
		$stmt->execute();
		$stmt->close();
		echo 'New Supplier Added';
	}			
	public function getSupplier(){
		$sqlQuery = "SELECT * FROM ".$this->supplierTable." WHERE supplier_id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$supplierId = (int)$_POST["supplier_id"];
		$stmt->bind_param("i", $supplierId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		echo json_encode($row);
	}
	public function updateSupplier() {
		if($_POST['supplier_id']) {	
			$sqlUpdate = "UPDATE ".$this->supplierTable." SET supplier_name = ?, mobile = ?, address = ? WHERE supplier_id = ?";
			$stmt = $this->dbConnect->prepare($sqlUpdate);
			$supplierName = trim($_POST['supplier_name']);
			$mobile = trim($_POST['mobile']);
			$address = trim($_POST['address']);
			$supplierId = (int)$_POST['supplier_id'];
			$stmt->bind_param("sssi", $supplierName, $mobile, $address, $supplierId);
			$stmt->execute();
			$stmt->close();
			echo 'Supplier Edited';
		}	
	}	
	public function deleteSupplier(){
		$sqlQuery = "DELETE FROM ".$this->supplierTable." WHERE supplier_id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$supplierId = (int)$_POST['supplier_id'];
		$stmt->bind_param("i", $supplierId);
		$stmt->execute();
		$stmt->close();
	}
	// purchase
	public function listPurchase(){		
		$sqlQuery = "SELECT ph.*, p.pname, s.supplier_name FROM ".$this->purchaseTable." as ph
			INNER JOIN ".$this->productTable." as p ON p.pid = ph.product_id 
			INNER JOIN ".$this->supplierTable." as s ON s.supplier_id = ph.supplier_id ";
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'ph.purchase_id',
			1 => 'p.pname',
			2 => 'ph.quantity',
			3 => 's.supplier_name'
		), 'ph.purchase_id DESC');
		$sqlQuery .= $this->getLimitClause();		
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$purchaseData = array();	
		while( $purchase = mysqli_fetch_assoc($result) ) {			
			$productRow = array();
			$productRow[] = $purchase['purchase_id'];
			$productRow[] = $purchase['pname'];
			$productRow[] = $purchase['quantity'];			
			$productRow[] = $purchase['supplier_name'];			
			$productRow[] = '<div class="btn-group btn-group-sm"><button type="button" name="update" id="'.$purchase["purchase_id"].'" class="btn btn-primary btn-sm rounded-0  update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$purchase["purchase_id"].'" class="btn btn-danger btn-sm rounded-0  delete" title="Delete"><i class="fa fa-trash"></i></button></div>';
			$purchaseData[] = $productRow;
						
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$purchaseData
		);
		echo json_encode($output);		
	}
	public function productDropdownList(){	
		$sqlQuery = "SELECT * FROM ".$this->productTable." ORDER BY pname ASC";
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$dropdownHTML = '';
		while( $product = mysqli_fetch_assoc($result) ) {	
			$dropdownHTML .= '<option value="' . htmlspecialchars($product["pid"], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($product["pname"], ENT_QUOTES, 'UTF-8') . '</option>';
		}
		return $dropdownHTML;
	}
	public function addPurchase() {		
		$productId = $this->getPositiveInt($_POST['product'] ?? 0);
		$quantity = $this->getPositiveInt($_POST['quantity'] ?? 0);
		$supplierId = $this->getPositiveInt($_POST['supplierid'] ?? 0);
		if ($productId <= 0 || $quantity <= 0 || $supplierId <= 0) {
			echo 'Invalid purchase input';
			return;
		}

		$this->dbConnect->begin_transaction();
		try {
			$sqlInsert = "INSERT INTO ".$this->purchaseTable."(product_id, quantity, supplier_id) VALUES (?, ?, ?)";
			$stmt = $this->dbConnect->prepare($sqlInsert);
			$stmt->bind_param("iii", $productId, $quantity, $supplierId);
			$stmt->execute();
			$stmt->close();
			$this->dbConnect->commit();
			echo 'New Purchase Added';
		} catch (Exception $e) {
			$this->dbConnect->rollback();
			echo 'Failed to add purchase';
		}
	}	
	public function getPurchaseDetails(){
		$sqlQuery = "SELECT * FROM ".$this->purchaseTable." WHERE purchase_id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$purchaseId = (int)$_POST["purchase_id"];
		$stmt->bind_param("i", $purchaseId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		echo json_encode($row);
	}
	public function updatePurchase() {
		if($_POST['purchase_id']) {	
			$productId = $this->getPositiveInt($_POST['product'] ?? 0);
			$quantity = $this->getPositiveInt($_POST['quantity'] ?? 0);
			$supplierId = $this->getPositiveInt($_POST['supplierid'] ?? 0);
			$purchaseId = $this->getPositiveInt($_POST['purchase_id'] ?? 0);
			if ($productId <= 0 || $quantity <= 0 || $supplierId <= 0 || $purchaseId <= 0) {
				echo 'Invalid purchase input';
				return;
			}

			$this->dbConnect->begin_transaction();
			try {
				$sqlUpdate = "UPDATE ".$this->purchaseTable." SET product_id = ?, quantity = ?, supplier_id = ? WHERE purchase_id = ?";
				$stmt = $this->dbConnect->prepare($sqlUpdate);
				$stmt->bind_param("iiii", $productId, $quantity, $supplierId, $purchaseId);
				$stmt->execute();
				$stmt->close();
				$this->dbConnect->commit();
				echo 'Purchase Edited';
			} catch (Exception $e) {
				$this->dbConnect->rollback();
				echo 'Failed to update purchase';
			}
		}	
	}	
	public function deletePurchase(){
		$purchaseId = $this->getPositiveInt($_POST['purchase_id'] ?? 0);
		if ($purchaseId <= 0) {
			echo 'Invalid purchase id';
			return;
		}

		$this->dbConnect->begin_transaction();
		try {
			$sqlQuery = "DELETE FROM ".$this->purchaseTable." WHERE purchase_id = ?";
			$stmt = $this->dbConnect->prepare($sqlQuery);
			$stmt->bind_param("i", $purchaseId);
			$stmt->execute();
			$stmt->close();
			$this->dbConnect->commit();
		} catch (Exception $e) {
			$this->dbConnect->rollback();
			echo 'Failed to delete purchase';
		}
	}
	// order
	public function listOrders(){		
		$sqlQuery = "SELECT * FROM ".$this->orderTable." as o
			INNER JOIN ".$this->customerTable." as c ON c.id = o.customer_id
			INNER JOIN ".$this->productTable." as p ON p.pid = o.product_id ";		
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'o.order_id',
			1 => 'p.pname',
			2 => 'o.total_shipped',
			3 => 'c.name'
		), 'o.order_id DESC');
		$sqlQuery .= $this->getLimitClause();		
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$orderData = array();	
		while( $order = mysqli_fetch_assoc($result) ) {			
			$orderRow = array();
			$orderRow[] = $order['order_id'];
			$orderRow[] = $order['pname'];
			$orderRow[] = $order['total_shipped'];	
			$orderRow[] = $order['name'];			
			$orderRow[] = '<div class="btn-group btn-group-sm"><button type="button" name="update" id="'.$order["order_id"].'" class="btn btn-primary btn-sm rounded-0  update" title="Update"><i class="fa fa-edit"></i></button><button type="button" name="delete" id="'.$order["order_id"].'" class="btn btn-danger btn-sm rounded-0  delete" title="Delete"><i class="fa fa-trash"></i></button></button';
			$orderData[] = $orderRow;
						
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$orderData
		);
		echo json_encode($output);		
	}
	public function addOrder() {		
		$productId = $this->getPositiveInt($_POST['product'] ?? 0);
		$totalShipped = $this->getPositiveInt($_POST['shipped'] ?? 0);
		$customerId = $this->getPositiveInt($_POST['customer'] ?? 0);
		if ($productId <= 0 || $totalShipped <= 0 || $customerId <= 0) {
			echo 'Invalid order input';
			return;
		}

		$this->dbConnect->begin_transaction();
		try {
			$inventoryInHand = $this->calculateInventoryInHand($productId);
			if ($inventoryInHand < 0) {
				$this->dbConnect->rollback();
				echo 'Product not found';
				return;
			}
			if ($totalShipped > $inventoryInHand) {
				$this->dbConnect->rollback();
				echo 'Insufficient stock';
				return;
			}

			$sqlInsert = "INSERT INTO ".$this->orderTable."(product_id, total_shipped, customer_id) VALUES (?, ?, ?)";
			$stmt = $this->dbConnect->prepare($sqlInsert);
			$stmt->bind_param("iii", $productId, $totalShipped, $customerId);
			$stmt->execute();
			$stmt->close();
			$this->dbConnect->commit();
			echo 'New order added';
		} catch (Exception $e) {
			$this->dbConnect->rollback();
			echo 'Failed to add order';
		}
	}		
	public function getOrderDetails(){
		$sqlQuery = "SELECT * FROM ".$this->orderTable." WHERE order_id = ?";
		$stmt = $this->dbConnect->prepare($sqlQuery);
		$orderId = (int)$_POST["order_id"];
		$stmt->bind_param("i", $orderId);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		$stmt->close();
		echo json_encode($row);
	}
	public function updateOrder() {
		if($_POST['order_id']) {	
			$productId = $this->getPositiveInt($_POST['product'] ?? 0);
			$totalShipped = $this->getPositiveInt($_POST['shipped'] ?? 0);
			$customerId = $this->getPositiveInt($_POST['customer'] ?? 0);
			$orderId = $this->getPositiveInt($_POST['order_id'] ?? 0);
			if ($productId <= 0 || $totalShipped <= 0 || $customerId <= 0 || $orderId <= 0) {
				echo 'Invalid order input';
				return;
			}

			$this->dbConnect->begin_transaction();
			try {
				$inventoryInHand = $this->calculateInventoryInHand($productId, $orderId);
				if ($inventoryInHand < 0) {
					$this->dbConnect->rollback();
					echo 'Product not found';
					return;
				}
				if ($totalShipped > $inventoryInHand) {
					$this->dbConnect->rollback();
					echo 'Insufficient stock';
					return;
				}

				$sqlUpdate = "UPDATE ".$this->orderTable." SET product_id = ?, total_shipped = ?, customer_id = ? WHERE order_id = ?";
				$stmt = $this->dbConnect->prepare($sqlUpdate);
				$stmt->bind_param("iiii", $productId, $totalShipped, $customerId, $orderId);
				$stmt->execute();
				$stmt->close();
				$this->dbConnect->commit();
				echo 'Order Edited';
			} catch (Exception $e) {
				$this->dbConnect->rollback();
				echo 'Failed to update order';
			}
		}	
	}	
	public function deleteOrder(){
		$orderId = $this->getPositiveInt($_POST['order_id'] ?? 0);
		if ($orderId <= 0) {
			echo 'Invalid order id';
			return;
		}

		$this->dbConnect->begin_transaction();
		try {
			$sqlQuery = "DELETE FROM ".$this->orderTable." WHERE order_id = ?";
			$stmt = $this->dbConnect->prepare($sqlQuery);
			$stmt->bind_param("i", $orderId);
			$stmt->execute();
			$stmt->close();
			$this->dbConnect->commit();
		} catch (Exception $e) {
			$this->dbConnect->rollback();
			echo 'Failed to delete order';
		}
	}
	public function customerDropdownList(){	
		$sqlQuery = "SELECT * FROM ".$this->customerTable." ORDER BY name ASC";
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$dropdownHTML = '';
		while( $customer = mysqli_fetch_assoc($result) ) {	
			$dropdownHTML .= '<option value="' . htmlspecialchars($customer["id"], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($customer["name"], ENT_QUOTES, 'UTF-8') . '</option>';
		}
		return $dropdownHTML;
	}
	public function getInventoryDetails(){		
		$sqlQuery = "SELECT p.pid, p.pname, p.model, p.quantity as product_quantity, s.quantity as recieved_quantity, r.total_shipped
			FROM ".$this->productTable." as p
			LEFT JOIN ".$this->purchaseTable." as s ON s.product_id = p.pid
			LEFT JOIN ".$this->orderTable." as r ON r.product_id = p.pid ";		
		$sqlQuery .= $this->getOrderByClause($_POST["order"] ?? array(), array(
			0 => 'p.pid',
			1 => 'p.pname',
			2 => 'product_quantity',
			3 => 'recieved_quantity',
			4 => 'total_shipped'
		), 'p.pid DESC');
		$sqlQuery .= $this->getLimitClause();		
		$result = mysqli_query($this->dbConnect, $sqlQuery);
		$numRows = mysqli_num_rows($result);
		$inventoryData = array();	
		$i = 1;
		while( $inventory = mysqli_fetch_assoc($result) ) {	

			if(!$inventory['recieved_quantity']) {
				$inventory['recieved_quantity'] = 0;
			}
			if(!$inventory['total_shipped']) {
				$inventory['total_shipped'] = 0;
			}
			
			$inventoryInHand = ($inventory['product_quantity'] + $inventory['recieved_quantity']) - $inventory['total_shipped'];
		
			$inventoryRow = array();
			$inventoryRow[] = $i++;
			$inventoryRow[] = "<div class='lh-1'><div>" . htmlspecialchars($inventory['pname'], ENT_QUOTES, 'UTF-8') . "</div><div class='fw-bolder text-muted'><small>" . htmlspecialchars($inventory['model'], ENT_QUOTES, 'UTF-8') . "</small></div></div>";
			// $inventoryRow[] = $inventory['pname'];
			// $inventoryRow[] = $inventory['model'];
			$inventoryRow[] = $inventory['product_quantity'];
			$inventoryRow[] = $inventory['recieved_quantity'];	
			$inventoryRow[] = $inventory['total_shipped'];
			$inventoryRow[] = $inventoryInHand;			
			$inventoryData[] = $inventoryRow;						
		}
		$output = array(
			"draw"				=>	intval($_POST["draw"]),
			"recordsTotal"  	=>  $numRows,
			"recordsFiltered" 	=> 	$numRows,
			"data"    			=> 	$inventoryData
		);
		echo json_encode($output);		
	}
}
?>