<?php

namespace FlysystemMsgraph;

use Exception;
use GuzzleHttp\Stream\GuzzleStreamWrapper;
use League\Flysystem;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;
use stdClass;

class MsgraphAdapter implements Flysystem\AdapterInterface
{
    protected $graph;

    protected $config;

    public function __construct(array $config)
    {
	$this->validateConfig($config);

	$this->config = $config;

        $graph = new Graph();
        $graph->setAccessToken($this->getAccessToken());
        $this->graph = $graph;
    }

    public function write($path, $contents, Config $config)
    {
    	$path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;

        $file = $this->graph
            ->createRequest('PUT', $path . ':/content')
            ->attachBody($contents)
            ->setReturnType(Model\DriveItem::class)
            ->execute();

        return [
            'type' => 'file',
            'path' => $path,
            'timestamp' => $file->getLastModifiedDateTime()->getTimestamp(),
            'size' => $file->getSize(),
            'mimetype' => $file->getFile()->getMimeType(),
            'visibility' => 'public',
        ];
    }

    public function writeStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    public function update($path, $contents, Config $config)
    {
    }

    public function updateStream($path, $resource, Config $config)
    {
    }

    public function rename($path, $newpath)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;

        $newFilePathArray = explode('/', $newpath);
        $newFileName = array_pop($newFilePathArray);
        $newPath = count($newFilePathArray)
            ? '/drives/' .
                $this->config['drive_id'] .
                '/root:/' .
                implode('/', $newFilePathArray)
            : '/drives/' . $this->config['drive_id'] . '/root';

        $this->graph
            ->createRequest(
                'PATCH',
                '/drives/' .
                    $this->config['drive_id'] .
                    '/items/' .
                    $this->getFile($path)->getId()
            )
            ->attachBody([
                'parentReference' => [
                    'driveId' => $this->config['drive_id'],
                    'id' => $this->getFile($newPath)->getId(),
                ],
                'name' => $newFileName,
            ])
            ->execute()
            ->getBody();
        return true;
    }

    public function copy($path, $newpath)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;

        $newFilePathArray = explode('/', $newpath);
        $newFileName = array_pop($newFilePathArray);
        $newPath = count($newFilePathArray)
            ? '/drives/' .
                $this->config['drive_id'] .
                '/root:/' .
                implode('/', $newFilePathArray)
            : '/drives/' . $this->config['drive_id'] . '/root';

        $this->graph
            ->createRequest(
                'POST',
                '/drives/' .
                    $this->config['drive_id'] .
                    '/items/' .
                    $this->getFile($path)->getId() .
                    '/copy'
            )
            ->attachBody([
                'parentReference' => [
                    'driveId' => $this->config['drive_id'],
                    'id' => $this->getFile($newPath)->getId(),
                ],
                'name' => $newFileName,
            ])
            ->execute()
            ->getBody();
        return true;
    }

    public function delete($path)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;

        $this->graph
            ->createRequest(
                'DELETE',
                '/drives/' .
                    $this->config['drive_id'] .
                    '/items/' .
                    $this->getFile($path)->getId()
            )
            ->execute()
            ->getBody();
        return true;
    }

    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    public function createDir($dirname, Config $config)
    {
        $newDirPathArray = explode('/', $dirname);
        $newDirName = array_pop($newDirPathArray);
        $parentItem = count($newDirPathArray)
            ? '/drives/' .
                $this->config['drive_id'] .
                '/root:/' .
                implode('/', $newDirPathArray)
            : '/drives/' . $this->config['drive_id'] . '/root';

        $dirItem = $this->graph
            ->createRequest(
                'POST',
                '/drives/' .
                    $this->config['drive_id'] .
                    '/items/' .
                    $this->getFile($parentItem)->getId() .
                    '/children'
            )
            ->attachBody([
                'name' => $newDirName,
                'folder' => new stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename',
            ])
            ->setReturnType(Model\DriveItem::class)
            ->execute();
        return [
            'type' => 'file',
            'path' =>
                implode('/', $newDirPathArray) . '/' . $dirItem->getName(),
            'timestamp' => $dirItem->getLastModifiedDateTime()->getTimestamp(),
            'size' => $dirItem->getSize(),
            'mimetype' => null,
            'visibility' => 'public',
        ];
    }

    public function setVisibility($path, $visibility)
    {
    }

    public function has($path)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;
        try {
            $this->getFile($path);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function read($path)
    {
        if (!($object = $this->readStream($path))) {
            return false;
        }

        $object['contents'] = stream_get_contents($object['stream']);
        unset($object['stream']);

        return $object;
    }

    public function readStream($path)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;

        $file = $this->graph
            ->createRequest('GET', $path)
            ->execute()
            ->getBody();

        $client = new Client();
        $response = $client->request(
            'GET',
            $file['@microsoft.graph.downloadUrl']
        );
        $stream = StreamWrapper::getResource($response->getBody());
        return compact('stream');
        return [
            'type' => 'file',
            'path' => $path,
            'stream' => $response->getBody(),
        ];
    }

    public function listContents($directory = '', $recursive = false)
    {
        $path = $directory
            ? '/drives/' .
                $this->config['drive_id'] .
                '/root:/' .
                $directory .
                ':/children'
            : '/drives/' . $this->config['drive_id'] . '/root/children';

        /** @var Model\DriveItem[] $items */
        $items = $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(Model\DriveItem::class)
            ->execute();

        return array_map(function (Model\DriveItem $item) use ($directory) {
            if ($item->getFolder() && !$item->getPackage()) {
                return [
                    'type' => 'dir',
                    'path' => rtrim(($directory ? "{$directory}/" : '') . $item->getName(), '/'),
                    'timestamp' => $item->getLastModifiedDateTime()->getTimestamp(),
                    'size' => $item->getSize(),
                    'mimetype' => null,
                    'visibility' => 'public',
                ];
            } else {
                return [
                    'type' => 'file',
                    'path' => rtrim(($directory ? "{$directory}/" : '') . $item->getName(), '/'),
                    'timestamp' => $item->getLastModifiedDateTime()->getTimestamp(),
                    'size' => $item->getSize(),
                    'mimetype' => $item->getFile()
                        ? $item->getFile()->getMimeType()
                        : null,
                    'visibility' => 'public',
                ];
    
            }
        }, $items);
    }

    public function getMetadata($path)
    {
        $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path;
        $item = $this->getDriveItem($path);
        if ($item->getFolder() && !$item->getPackage()) {
            return [
                'type' => 'dir',
                'path' => $path,
                'timestamp' => $item->getLastModifiedDateTime()->getTimestamp(),
                'size' => $item->getSize(),
                'mimetype' => null,
                'visibility' => 'public',
                'webUrl' => $item->getWebUrl(),
            ];
        } else {
            return [
                'type' => 'file',
                'path' => $path,
                'timestamp' => $item->getLastModifiedDateTime()->getTimestamp(),
                'size' => $item->getSize(),
                'mimetype' => $item->getFile()
                    ? $item->getFile()->getMimeType()
                    : null,
                'visibility' => 'public',
                'webUrl' => $item->getWebUrl(),
            ];
        }
    }

    public function getSize($path)
    {
        return [
            'size' => $this->getDriveItem(
                $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path
            )->getSize(),
        ];
    }

    public function getMimetype($path)
    {
        $item = $this->getDriveItem(
            $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path
        );
        return [
            'mimetype' => $item->getFile()
                ? $item->getFile()->getMimeType()
                : null,
        ];
    }

    public function getTimestamp($path)
    {
        return [
            'timestamp' => $this->getDriveItem(
                $path = '/drives/' . $this->config['drive_id'] . '/root:/' . $path
            )
                ->getLastModifiedDateTime()
                ->getTimestamp(),
        ];
    }

    public function getVisibility($path)
    {
    }

    protected function validateConfig(array $config) {
    	if (empty($config['drive_id'])
		|| empty($config['tenant'])
		|| empty($config['client_id'])
		|| empty($config['client_secret'])
	) {
		throw new Exception('Invalid configuration for msgraphq adapter');
	}
    }
		    

    protected function getFile(string $path): Model\File
    {
        return $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(Model\File::class)
            ->execute();
    }

    protected function getDriveItem(string $path): Model\DriveItem
    {
        return $this->graph
            ->createRequest('GET', $path)
            ->setReturnType(Model\DriveItem::class)
            ->execute();
    }

    protected function getAccessToken()
    {
        $guzzle = new \GuzzleHttp\Client();
        $url =
            'https://login.microsoftonline.com/' .
            $this->config['tenant'] .
            '/oauth2/v2.0/token';
        $token = json_decode(
            $guzzle
                ->post($url, [
                    'form_params' => [
                        'client_id' => $this->config['client_id'],
                        'client_secret' => $this->config[
                            'client_secret'
                        ],
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                ])
                ->getBody()
                ->getContents()
        );

        return $token->access_token;
    }
}
