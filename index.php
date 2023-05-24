<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;


$merlinFaceApiUrl = 'http://merlinface.com:12345/api/';


$dbHost = 'db';
$dbName = 'test';
$dbUser = 'root';
$dbPass = 'root';


$client = new Client();


function isTaskReady($taskId)
{
    $pdo = createDatabaseConnection();
    $query = $pdo->prepare('SELECT result FROM tasks WHERE id = :id');
    $query->bindParam(':id', $taskId, PDO::PARAM_INT);
    $query->execute();
    $result = $query->fetchColumn();
    return $result !== false;
}


function createDatabaseConnection()
{
    $dsn = "mysql:host={$GLOBALS['dbHost']};dbname={$GLOBALS['dbName']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $GLOBALS['dbUser'], $GLOBALS['dbPass'], $options);
    } catch (PDOException $e) {
        throw new RuntimeException('Failed to connect to the database: ' . $e->getMessage());
    }
}


function handlePostRequest()
{
    $name = $_POST['name'];
    $photo = $_FILES['photo'];

    $pdo = createDatabaseConnection();
    $query = $pdo->prepare('SELECT id FROM tasks WHERE name = :name AND photo = :photo');
    $query->bindParam(':name', $name, PDO::PARAM_STR);
    $query->bindParam(':photo', $photo['name'], PDO::PARAM_STR);
    $query->execute();
    $taskId = $query->fetchColumn();

    if ($taskId) {
        $result = getTaskResult($taskId);

        $response = [
            'status' => 'ready',
            'task' => $taskId,
            'result' => $result
        ];

        http_response_code(200);
        echo json_encode($response);
        return;
    }


    $tempPhotoPath = '/tmp/' . $photo['name'];
    move_uploaded_file($photo['tmp_name'], $tempPhotoPath);


    $pdo->beginTransaction();
    $query = $pdo->prepare('INSERT INTO tasks (name, photo) VALUES (:name, :photo)');
    $query->bindParam(':name', $name, PDO::PARAM_STR);
    $query->bindParam(':photo', $photo['name'], PDO::PARAM_STR);
    $query->execute();
    $taskId = $pdo->lastInsertId();
    $pdo->commit();

    try {

        $response = $client->request('POST', $GLOBALS['merlinFaceApiUrl'], [
            'multipart' => [
                [
                    'name' => 'name',
                    'contents' => $name
                ],
                [
                    'name' => 'photo',
                    'contents' => fopen($tempPhotoPath, 'r')
                ]
            ]
        ]);

        $responseData = json_decode($response->getBody(), true);
        $status = $responseData['status'];
        $result = $responseData['result'];

        if ($status === 'ready') {
            // Обработка готового результата
            markTaskAsCompleted($taskId, $result);
        } elseif ($status === 'wait') {
            // Повторный запрос через пару секунд
            retryTaskRequest($taskId, $responseData['retry_id']);
        }

        $response = [
            'status' => 'received',
            'task' => $taskId,
            'result' => null
        ];

        http_response_code(200);
        echo json_encode($response);
    } catch (GuzzleException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to communicate with MerlinFace service']);
    } finally {
        unlink($tempPhotoPath);
    }
}


function markTaskAsCompleted($taskId, $result)
{
    $pdo = createDatabaseConnection();
    $query = $pdo->prepare('UPDATE tasks SET result = :result WHERE id = :id');
    $query->bindParam(':result', $result, PDO::PARAM_STR);
    $query->bindParam(':id', $taskId, PDO::PARAM_INT);
    $query->execute();
}

function retryTaskRequest($taskId, $retryId)
{
    sleep(2); // Подождать 2 секунды перед повторным запросом

    try {
        $response = $client->request('POST', $GLOBALS['merlinFaceApiUrl'], [
            'multipart' => [
                [
                    'name' => 'retry_id',
                    'contents' => $retryId
                ]
            ]
        ]);

        $responseData = json_decode($response->getBody(), true);
        $status = $responseData['status'];
        $result = $responseData['result'];

        if ($status === 'ready') {
            // Обработка готового результата
            markTaskAsCompleted($taskId, $result);
        } elseif ($status === 'wait') {
            // Рекурсивный повторный запрос
            retryTaskRequest($taskId, $responseData['retry_id']);
        }
    } catch (GuzzleException $e) {

    }
}


function handleGetRequest()
{
    $taskId = $_GET['task_id'];

    if (!is_numeric($taskId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid task_id']);
        return;
    }

    if (isTaskReady($taskId)) {
        $result = getTaskResult($taskId);

        $response = [
            'status' => 'ready',
            'result' => $result
        ];

        http_response_code(200);
        echo json_encode($response);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'not_found']);
    }
}


function getTaskResult($taskId)
{
    $pdo = createDatabaseConnection();
    $query = $pdo->prepare('SELECT result FROM tasks WHERE id = :id');
    $query->bindParam(':id', $taskId, PDO::PARAM_INT);
    $query->execute();
    $result = $query->fetchColumn();
    return $result;
}


function handleRequest()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handlePostRequest();
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleGetRequest();
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
    }
}


handleRequest();