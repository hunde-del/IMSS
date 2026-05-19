<?php 
ob_start();
require_once 'config.php';
include('inc/header.php');
$loginError = '';

if (!empty($_POST['email']) && !empty($_POST['pwd'])) {
	if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
		$loginError = "Invalid request token. Please refresh and try again.";
	} else {
		include 'Inventory.php';
		$inventory = new Inventory();
		
		// Validate email format
		$email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
		if (!$email) {
			$loginError = "Invalid email format!";
		} else {
			$login = $inventory->login($email, $_POST['pwd']); 
			if (!empty($login)) {
				session_regenerate_id(true);
				$_SESSION['userid'] = $login[0]['userid'];
				$_SESSION['name'] = $login[0]['name'];			
				redirect('index.php');
			} else {
				$loginError = "Invalid email or password!";
			}
		}
	}
}
?>
<style>
html,
body,
body>.container {
    height: 95%;
    width: 100%;
}
body>.container {
	display:flex;
	flex-direction:column;
	align-items:center;
	justify-content:center;
}
#title{
	text-shadow:2px 2px 5px #000;
} 
</style>
<?php include('inc/container.php');?>

	<h1 class="text-center my-4 py-3 text-light" id="title">Inventory Management System - PHP</h1>	
	<div class="col-lg-4 col-md-5 col-sm-10 col-xs-12">
		<div class="card rounded-0 shadow">
			<div class="card-header">
				<div class="card-title h3 text-center mb-0 fw-bold">Login</div>
			</div>
			<div class="card-body">
				<div class="container-fluid">
					<form method="post" action="">
						<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
						<div class="form-group">
						<?php if ($loginError ) { ?>
							<div class="alert alert-danger rounded-0 py-1"><?php echo $loginError; ?></div>
						<?php } ?>
						</div>
						<div class="mb-3">
							<label for="email" class="control-label">Email</label>
							<input name="email" id="email" type="email" class="form-control rounded-0" placeholder="Email address" autofocus="" value="<?= htmlspecialchars(isset($_POST['email']) ? $_POST['email'] : '', ENT_QUOTES, 'UTF-8') ?>" required>
						</div>
						<div class="mb-3">
							<label for="password" class="control-label">Password</label>
							<input type="password" class="form-control rounded-0" id="password" name="pwd" placeholder="Password" required>
						</div>  
						<div class="d-grid">
							<button type="submit" name="login" class="btn btn-primary rounded-0">Login</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>		
<?php include('inc/footer.php');?>