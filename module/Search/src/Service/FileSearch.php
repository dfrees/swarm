<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Search\Service;

use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\I18n\TranslatorFactory;
use Exception;
use Interop\Container\ContainerInterface;
use Laminas\Http\Client as HttpClient;
use Laminas\Json\Json;
use Redis\Model\AbstractDAO;
use Application\Log\SwarmLogger;
use Search\Filter\ISearch;

/**
 * Class FileSearch. Service to implement file path and content search
 * @package Reviews\Service
 */
class FileSearch implements IFileSearch, InvokableService
{
    private $services;
    private $messages;

    const P4SEARCH_ERROR_MESSAGE = 'p4SearchErrorMessage';

    /**
     * FileSearch constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services                               = $services;
        $translator                                   = $services->get(TranslatorFactory::SERVICE);
        $this->messages[self::P4SEARCH_ERROR_MESSAGE] = $translator->t('File Search is not available at the moment.');
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function search($context, $options): array
    {
        if ($context === self::FILE_PATH && !$options[self::P4_SEARCH_HOST]) {
            return $this->callFstat($options);
        } else {
            return $this->callP4Search($context, $options);
        }
    }

    /**
     * Builds a default array to describe search response
     * @return array
     */
    private function buildResponse()
    {
        return [
            self::FILES_COUNT => 0,
            self::MAX_SCORE => null,
            self::RESULTS => []
        ];
    }

    /**
     * Executes the actual p4 fstat command with parameters to fetch the matches for file path
     * @param array     $options    the search options includes: term, path, limit and more.
     * @return array
     */
    private function callFstat($options): array
    {
        $results = $this->buildResponse();
        $p4      = $this->services->get(ConnectionFactory::P4);
        $path    = trim($options[ISearch::PATH], '/');
        $keyword = $options[AbstractDAO::SEARCH_TERM];

        $keywords = preg_split('/[\s\/]+/', isset($keyword) ? $keyword : '');
        $keywords = array_unique(array_filter($keywords, 'strlen'));

        // if we have no path, search shallow and include dirs
        $dirs = !$path;
        $path = $path ? "//$path/..." : "//*/*";

        $lower  = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        $upper  = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
        $filter = '';

        foreach ($keywords as $keyword) {
            $regex = preg_replace_callback(
                '/(.)/u', function ($matches) use ($lower, $upper) {
                    return '[\\' . $lower($matches[0]) . '\\' . $upper($matches[0]) . ']';
                }, $keyword
            );
            $filter .= ' depotFile~=' . $regex;
            $filter .= $dirs ? '|dir~=' . $regex : '';
        }

        try {
            $files = $p4->run(
                'fstat',
                array_filter(
                    [
                    $dirs ? '-Dx' : '',
                    '-T depotFile,headChange,headRev,headType,headAction,fileSize' . ($dirs ? ',dir' : ''),
                    '-F' . $filter,
                    $path
                    ], 'strlen'
                )
            );
            $data  = $files->getData();
            if (isset($data) && $data) {
                $filesCount = count($data);
                if ($filesCount) {
                    $results[self::FILES_COUNT] = $filesCount;
                }
                $results[self::MAX_SCORE] = 100;

                foreach ($data as $match) {
                    $depotPath                = isset($match['depotFile']) ? $match['depotFile'] : $match['dir'];
                    $results[self::RESULTS][] = [
                        self::RESULT_TYPE => 'file',
                        self::RESULT_CHANGE => $match['headChange'],
                        self::RESULT_DEPOT_FILE => $depotPath,
                        self::RESULT_FILE_NAME => basename($depotPath),
                        self::RESULT_ACTION => $match['headAction'],
                        self::RESULT_FILE_TYPE => $match['headType'],
                        self::RESULT_REV => $match['headRev'],
                        self::RESULT_FILE_SIZE => isset($match['fileSize']) && $match['fileSize'],
                    ];
                }
            }
        } catch (\Exception $e) {
            // ignore errors
        }
        return $results;
    }

    /**
     * Executes the actual P4Search APIs fetch the matches for file path or content
     * @param string $context the context filePath or fileContent
     * @param array $options the search options includes: term, path, limit and more.
     * @return array
     * @throws Exception
     */
    private function callP4Search($context, $options)
    {
        $host    = trim($options[self::P4_SEARCH_HOST], '/');
        $apiPath = $options[self::P4_SEARCH_API_PATH];
        $results = $this->buildResponse();

        if (strlen($host)) {
            $url      = $host . $apiPath;
            $path     = trim($options[ISearch::PATH], '/');
            $path     = $path ? "//$path/*" : "//*/*";
            $data     = $this->prepareP4SearchBody($context, $options[AbstractDAO::SEARCH_TERM], $path);
            $response = $this->doRequest($url, $data);
            if ($response && $response->isSuccess()) {
                $body = json_decode($response->getBody(), true);
                $hits = $body['hits'];

                $results[self::FILES_COUNT] = $hits['total']['value'];
                $results[self::MAX_SCORE]   = $hits['max_score'];

                foreach ($hits['hits'] as $hit) {
                    $match                    = $hit['_source'];
                    $fileName                 = isset(
                        $match['fileName']
                    ) ? $match['fileName'] : basename($match['depotFile']);
                    $results[self::RESULTS][] = [
                        self::RESULT_TYPE => $match['type'],
                        self::RESULT_CHANGE => $match['change'],
                        self::RESULT_DEPOT_FILE => $match['depotFile'],
                        self::RESULT_FILE_NAME => $fileName,
                        self::RESULT_ACTION => $match['action'],
                        self::RESULT_FILE_TYPE => $match['fileType'],
                        self::RESULT_REV => $match['rev'],
                        self::RESULT_FILE_SIZE => $match['fileSize'],
                    ];
                }
                return $results;
            } else {
                throw new Exception($this->messages[self::P4SEARCH_ERROR_MESSAGE]);
            }
        }
        return $results;
    }

    /**
     * Prepares the data the needs to be given as body of P4Search API raw endpoint
     * @param string    $context    the context filePath or fileContent
     * @param mixed     $term       term that we are searching for
     * @param string    $path       wildcard to narrow search to a depot path
     * @return array
     */
    private function prepareP4SearchBody($context, $term, $path)
    {
        $query = $context === self::FILE_PATH ? "revision" : "content";
        return [
            "queryType" => $query,
            "query" => [
                "bool" => [
                    "must" => [
                        [
                            "multi_match" => [
                                "query" => $term
                            ]
                        ]
                    ],
                    "filter" => [
                        "bool" => [
                            "must" => [
                                [
                                    "term" => [
                                        "type" => $query
                                    ]
                                ],
                                [
                                    "wildcard" => [
                                        "depotFile.tree" => $path
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            "collapse" => [
                "field" => "depotFile.keyword"
            ],
            "aggs" => [
                "totalCollapsed" => [
                    "cardinality" => [
                        "field" => "depotFile.keyword"
                    ]
                ]
            ],
            "from" => 0,
            "sort" => [
                [
                    "fileName.keyword" => [
                        "order" => "desc",
                        "unmapped_type" => "long"
                    ]
                ]
            ]
        ];
    }

    /**
     * Executes the actual P4Search API Request
     * @param string $url url for the P4Search API path
     * @param array $data request parameters that will be sent in the body or in the url
     * @param int $timeout optional timeout, defaults to 0 and is ignored if 0
     * @return mixed
     * @throws Exception
     */
    public function doRequest(string $url, array $data, int $timeout = 0)
    {
        $response = null;
        $logger   = $logger = $this->services->get(SwarmLogger::SERVICE);

        // extract the http client options; including any special overrides for our host
        $options  = $this->services->get(ConfigManager::CONFIG) + ['http_client_options' => []];
        $options  = (array)$options['http_client_options'];
        $identity = $this->services->get('auth')->getIdentity() + ['id' => null, 'ticket' => null];

        try {
            $client = new HttpClient;
            $client->setUri($url)
                ->setHeaders(['Content-Type' => 'application/json'])
                ->setMethod('post')
                ->setRawBody(Json::encode($data))
                ->setAuth($identity['id'], $identity['ticket']);

            // calculate options, including host based overrides, and set them
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $options = (array)$options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            if ($timeout > 0) {
                $options['timeout'] = $timeout;
            }
            $client->setOptions($options);
            $logger->trace(
                get_class($this) . ': P4Search API dispatch request ' . var_export(
                    $client->getRequest(),
                    true
                )
            );

            $response = $client->dispatch($client->getRequest());
        } catch (Exception $e) {
            $logger->err($e);
            throw new Exception($this->messages[self::P4SEARCH_ERROR_MESSAGE]);
        }
        return $response;
    }
}
