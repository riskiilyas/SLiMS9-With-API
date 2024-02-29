<?php
use SLiMS\DB;

$header = getallheaders();

if ((isset($header['SLiMS-Http-Cache']) || isset($header['slims-http-cache']))) {
    if ($sysconf['http']['cache']['lifetime'] > 0) header('Cache-Control: max-age=' . $sysconf['http']['cache']['lifetime']);
}

/*----------  Require dependencies  ----------*/
require 'lib/router.inc.php';
require __DIR__ . '/controllers/HomeController.php';
require __DIR__ . '/controllers/BiblioController.php';
require __DIR__ . '/controllers/MemberController.php';
require __DIR__ . '/controllers/SubjectController.php';
require __DIR__ . '/controllers/ItemController.php';
require __DIR__ . '/controllers/LoanController.php';
// require __DIR__ . '/auth.php'; // Include auth file



/*----------  Create router object  ----------*/
$router = new Router($sysconf, $dbs);
$router->setBasePath('api');


////////////////////////////// BAGIAN AUTH //////////////////////////////////
function generateToken() {
    return bin2hex(random_bytes(32));
}

function authenticate($username, $password) {
    if (validateCredentials($username, $password)) {
        $token = generateToken();
        // Store token in session or database associated with the user 
        $_SESSION['api_token'] = $token;
        return $token;
    } else {
        return false;
    }
}

function validateToken($token) {
    //  Retrieve token from session or database
    if (isset($_SESSION['api_token']) && $_SESSION['api_token'] === $token) {
        return true;
    } else {
        return false;
    }
}

function validateCredentials($username, $password) {
    $db = DB::getInstance();

    // 1. Fetch all members (remove the WHERE clause)
    $stmt = $db->query("select * from slims9_db_test.member");
    $stmt->execute(); 
    $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Check for username and password match
    foreach ($allMembers as $member) {
        if ($member['member_email'] === $username && 
        md5($password) === $member['mpasswd']) {
            return true; // Credentials are valid
        }
    }

    // If we reach here, no match was found
    return false; 
}

$router->map('POST', '/login', function() {
    $username = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $token = authenticate($username, $password);
    if (true) {
        echo json_encode(['token' => $token]);
    } else {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Invalid credentials']);
    }
});


$router->map('GET', '/validate', function() {    
    $rawData = file_get_contents('php://input'); 
    $data = json_decode($rawData, true); // Assuming JSON payload

    $token = $data['token'] ?? '';
    if (validateToken($token)) {
        echo json_encode(['success' => true]);
    } else {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['success' => false]);
    }
});

////////////////// BIBLIO API //////////////////////////////
$router->map('GET', '/biblio/popular', 'BiblioController@getPopular');
$router->map('GET', '/biblio/all', 'BiblioController@getAll');


$router->map('GET', '/pdf', function() {    
    $rawData = file_get_contents('php://input');
    $db = DB::getInstance();

    if (isset($_GET['biblio_id'])) {
        $biblio_id = $_GET['biblio_id'];
        $sql = "SELECT f.file_name
        FROM files AS f
        JOIN biblio_attachment AS ba ON f.file_id = ba.file_id
        WHERE ba.biblio_id = $biblio_id";

        // Execute the query
        $result = $db->query($sql);
        $result->execute(); 
        $row = $result->fetchAll(PDO::FETCH_ASSOC);
        if (count($row) == 0) {
            header("HTTP/1.0 404 Not Found");
            echo json_encode(["message" => "PDF file not found."]);
            exit;
        }

        $file = $row[0]['file_name'];
        // echo file_exists('repository/'.$file);
        // exit;

        if (file_exists('repository/'.$file)) {
            // Set appropriate headers for PDF file
            header('Content-Description: File Transfer');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($file) . '"');
            header('Content-Length: ' . filesize('repository/'.$file));
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            header('Pragma: public');
        
            // Output the PDF file
            readfile('repository/'.$file);
            exit;
        } else {
            // PDF file not found, return appropriate HTTP response
            header("HTTP/1.0 404 Not Found");
            echo json_encode(["message" => "PDF file not found."]);
        }
    } else {
        header("HTTP/1.0 404 Not Found");
        echo json_encode(["message" => "PDF file not found."]);
    }
});
/////////////////////////////////////////////////////////////////////////////////////

// /*----------  Create routes  ----------*/
// $router->map('GET', '/', 'HomeController@index');
// $router->map('GET', '/biblio/popular', 'BiblioController@getPopular');
// $router->map('GET', '/biblio/latest', 'BiblioController@getLatest');
// $router->map('GET', '/subject/popular', 'SubjectController@getPopular');
// $router->map('GET', '/subject/latest', 'SubjectController@getLatest');
// $router->map('GET', '/member/top', 'MemberController@getTopMember');
// $router->map('GET', '/biblio/gmd/[*:gmd]', 'BiblioController@getByGmd');
// $router->map('GET', '/biblio/coll_type/[*:coll_type]', 'BiblioController@getByCollType');

// /*----------  Admin  ----------*/
// $router->map('GET', '/biblio/total/all', 'BiblioController@getTotalAll');
// $router->map('GET', '/item/total/all', 'ItemController@getTotalAll');
// $router->map('GET', '/item/total/lent', 'ItemController@getTotalLent');
// $router->map('GET', '/item/total/available', 'ItemController@getTotalAvailable');
// $router->map('GET', '/loan/summary', 'LoanController@getSummary');
// $router->map('GET', '/loan/getdate/[*:start_date]', 'LoanController@getDate');
// $router->map('GET', '/loan/summary/[*:date]', 'LoanController@getSummaryDate');

/*----------  Custom route based on hook plugin  ----------*/
\SLiMS\Plugins::getInstance()->execute('custom_api_route', ['router' => $router]);

/*----------  Run matching route  ----------*/
$router->run();

// doesn't need template
exit();