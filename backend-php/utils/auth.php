<?php
//auth.php - register, login, logout
//usage(post):auth.php?action=register|Login\Logout / GET: auth.php?action = me
header('Content -Type: application/json');

//include your PDO connector
require_once __DIR__."/../db/db_connect.php";

function json($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

$action = strtolower($_GET['action'] ?? $_POST['action'] ?? '');

try{
    $pdo = db();

    /*----------REGISTER ----------*/

    if($action == 'register'){
        $name = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // validate

        if(!$name || !$email || !$password || !$confirm){
            json(['error' => 'All fields are required'], 422);
        }

        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            json(['error' => 'Invalid email address'], 422);
        }

        if($password !== $confirm){
            json (['error' => 'Passwords do not match'], 422);
        }

        // check if email exist or didn't

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users (full_name, email, password) VALUES (:name, :email, :password)");
        $ins->execute(['name' => $name, 'email' => $email, 'password' => $hash]);


        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['user_name'] = $name;
        $_SESSION['email'] = $email;

        json(['success'=>true,'message'=>'Registered','user'=>[
            'id'=>$_SESSION['User_id'],'full_name'=>$name,'email'=>$email
        ]]);
    }

    /*-------------LOGIN------------*/

if ($action === 'login') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
            json(['success'=>false,'message'=>'Invalid email or password'], 400);
        }

        $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($pass, $u['password_hash'])) {
            json(['success'=>false,'message'=>'Email or password incorrect'], 401);
        }

        $_SESSION['user_id']   = (int)$u['user_id'];
        $_SESSION['full_name']  = $u['full_name'];
        $_SESSION['email'] = $u['email'];

        json(['success'=>true,'message'=>'Logged in','user'=>[
            'id'=>$u['id'],'full_name'=>$u['full_name'],'email'=>$u['email']
        ]]);
    }

    /* -------- LOGOUT -------- */
    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        json(['success'=>true,'message'=>'Logged out']);
    }

    /* -------- ME (GET) -------- */
    if ($action === 'me') {
        if (!isset($_SESSION['user_id'])) {
            json(['success'=>false,'message'=>'Not authenticated'], 401);
        }
        json(['success'=>true,'user'=>[
            'user_id'=>$_SESSION['user_id'],
            'full_name'=>$_SESSION['name'],
            'email'=>$_SESSION['email']
        ]]);
    }

    // Unknown action
    json(['success'=>false,'message'=>'Unknown action'], 400);



    }

    catch (Throwable $e) {
    json(['success'=>false,'message'=>'Server error'], 500);
}
    

?>