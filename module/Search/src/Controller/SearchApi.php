<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Search\Controller;

use Api\Controller\AbstractRestfulController;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Redis\Model\AbstractDAO;
use Laminas\Http\Response;
use Api\IRequest;
use Search\Filter\ISearch;
use Exception;
use Application\Config\ConfigException;
use Search\Service\IFileSearch;
use Application\Model\IModelDAO;

class SearchApi extends AbstractRestfulController
{
    // API Request Params
    const API_VERSION = "v11";

    // Error code
    const ERROR_CODE = 'search-error';

    const RESULTS = 'results';

    /**
     * This API is used to search over a specified context and return records matching the given search term.
     *
     * It can accept two types of search:
     *      1) Includes:           A record is considered matched if it includes the search term anywhere within it.
     *                             This is the default and will actually run a starts with search first.
     *      2) Starts with only:   A record is only considered matched if it begins with the search term. This is a much
     *                             faster search. This type of search can be specified with the start_with_only param
     *
     * Supported context models are currently only user and group.
     *
     * Additionally, the total number of records returned can be limited by specifying a limit. Ten is the default.
     * If the number of matches is more than the limit, matches are truncated from the last context to the first.
     *
     * Furthermore, unless an 'ignoreExcludeList' param is specified with a value of true, any corresponding exclude
     * lists will be applied.
     *
     * For the contexts filePath and fileContent, the query param 'path' can be given with depot path to narrow
     * the search results to specific within depot path. For example: ?term=t&context=filePath&path=depot/main
     *
     * The searches are case-insensitive.
     *
     * Matches are returned as follows for:
     * user:        matches are returned as arrays of ids(usernames) and names(full names)
     * group:       matches are returned as arrays of ids(group id) and names(group names)
     * project:     matches are returned as arrays of ids(project id) and names(project names)
     * filePath:    matches are returned as arrays of type, change, depotFile, action, fileType, rev, fileSize, fileName
     * fileContent: matches are returned as arrays of type, change, depotFile, action, fileType, rev, fileSize, fileName
     *
     * If a limit is specified in the request, it is also returned with the data
     *
     * Examples:
     *  Request: /api/<version>/search?starts_with_only=true&term=J&context=user,group,project,filePath,fileContent
     *  Response: [
     *              'data' => [
     *                  'user'  => [
     *                      [
     *                          'id'   => 'jimbob',
     *                          'name' => 'Frank Smith'
     *                      ],
     *                  ],
     *                  'group'  => [
     *                      [
     *                          'id'   => 'jjp',
     *                          'name' => 'JJPs Group'
     *                      ],
     *                  ],
     *                  'project'  => [
     *                      [
     *                          'id'   => 'js-project',
     *                          'name' => 'Js Project'
     *                      ],
     *                  ],
     *                  'filePath'  => [
     *                      [
     *                          'type'   => 'revision',
     *                          'change' => 10032,
     *                          'depotFile' => '//depot/jim.txt',
     *                          'action' => 'branch',
     *                          'fileType' => 'text',
     *                          'rev' => 1,
     *                          'fileSize' => 1714,
     *                          'fileName' => 'jim.txt'
     *                      ],
     *                  ],
     *                  'fileContent'  => [
     *                      [
     *                          'type'   => 'content',
     *                          'change' => 12250,
     *                          'depotFile' => '//depot/jim.txt',
     *                          'action' => 'edit',
     *                          'fileType' => 'text',
     *                          'rev' => 3,
     *                          'fileSize' => 703,
     *                          'fileName' => 'jim.txt'
     *                      ],
     *                  ],
     *              ]
     *      Request:  /api/v10/search?&term=J&context=user,group&limit=3
     *      Response: [
     *                    'data' => [
     *                        'user'  => [
     *                            [
     *                                'User'     => 'jimbob',
     *                                'FullName' => 'Frank Smith'
     *                            ],
     *                            [
     *                                'User'     => 'raj',
     *                                'FullName' => 'Raja Paresh'
     *                            ]
     *                        ],
     *                        'group' => [
     *                            [
     *                                'Group' =>'sojourners',
     *                                'name'  =>'Sojourners',
     *                            ]
     *                        ],
     *                        'limit'  => 3
     *                    ]
     *                ]
     */
    public function searchAction()
    {
        // Returned with success response
        $data                          = [];
        $errors                        = null;
        $filter                        = $this->services->get(ISearch::SEARCH_FILTER);
        $request                       = $this->getRequest();
        $requestData                   = $request->getQuery()->toArray();
        $requestData[ISearch::CONTEXT] = explode(',', $requestData[ISearch::CONTEXT]);
        try {
            $filter->setData($requestData);
            if ($filter->isValid()) {
                $config = $this->services->get(IConfigDefinition::CONFIG);

                // Get matches for each model
                foreach ($filter->getValue(ISearch::CONTEXT) as $model) {
                    if (in_array($model, ISearch::FILE_CONTEXTS)) {
                        $data[$model] = $this->recordsForFiles(
                            $model,
                            $filter->getValue(ISearch::TERM),
                            $filter->getValue(ISearch::STARTS_WITH_ONLY),
                            $filter->getValue(ISearch::PATH),
                            $config
                        );
                    } else {
                        if (isset(ISearch::DAO_CONTEXTS[$model])) {
                            $data[$model] = [
                                self::RESULTS => $this->recordsForModel(
                                    $model,
                                    $filter->getValue(ISearch::TERM),
                                    $filter->getValue(ISearch::STARTS_WITH_ONLY),
                                    $config,
                                    $filter->getValue(IRequest::IGNORE_EXCLUDE_LIST)
                                )
                            ];
                        }
                    }
                }
                $this->limitResults($data, $filter->getValue(ISearch::LIMIT) ? $filter->getValue(ISearch::LIMIT) : 0);
            } else {
                $this->getResponse()->setStatusCode(Response::STATUS_CODE_422);
                $errors = $filter->getMessages();
            }
        } catch (Exception $e) {
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_500);
            $errors = [$this->buildMessage(Response::STATUS_CODE_500, $e->getMessage())];
        }
        if ($errors) {
            $json = $this->error($errors, $this->getResponse()->getStatusCode());
        } else {
            $json = $this->success($data);
        }
        return $json;
    }

    /**
     * Calls the fetchAll method of the given DAO model and passes it the appropriate params
     *
     * @param string   $model               model over whose records we are searching
     * @param mixed    $term                term that we are searching for
     * @param bool     $startsWithOnly      optionally, specify if you only want to search for records starting with the
     *                                      given term
     * @param array    $config              configuration array
     * @param bool     $ignoreExcludeList   whether exclude lists should be ignored
     *
     * @return array
     * @throws ConfigException
     */
    protected function recordsForModel(
        string $model,
        $term,
        bool $startsWithOnly,
        array $config,
        bool $ignoreExcludeList
    ) {
        $daoName     = ISearch::DAO_CONTEXTS[$model]['dao'];
        $dao         = $this->services->get($daoName);
        $excludeList = $ignoreExcludeList || !isset(ISearch::DAO_CONTEXTS[$model]['excludeList'])
            ? []
            : ConfigManager::getValue($config, ISearch::DAO_CONTEXTS[$model]['excludeList'], []);

        $options = [
            AbstractDAO::FETCH_SEARCH => [
                AbstractDAO::SEARCH_TERM               => $term,
                AbstractDAO::SEARCH_LIMIT              => 0,
                AbstractDAO::SEARCH_RETURN_RAW_ENTRIES => false,
                AbstractDAO::SEARCH_STARTS_WITH_ONLY   => $startsWithOnly,
                AbstractDAO::SEARCH_EXCLUDE_LIST       => $excludeList
            ]
        ];
        if ($daoName === IDao::PROJECT_DAO) {
            $options[IModelDAO::FILTER_PRIVATES] = true;
        }

        return $dao->fetchAll($options, $this->services->get(ConnectionFactory::P4_ADMIN));
    }

    /**
     * Calls the fetchAll method of the given DAO model and passes it the appropriate params
     *
     * @param string   $context           context over whose records we are searching
     * @param string   $term              term that we are searching for
     * @param bool     $startsWithOnly    optionally, specify if you only want to search for records starting with the
     *                                    given term
     * @param string   $path              for searching in a particular path
     * @param array    $config            configuration array
     * @throws ConfigException
     * @return array
     */
    protected function recordsForFiles($context, $term, $startsWithOnly, $path, $config)
    {
        $options = [
            AbstractDAO::FETCH_SEARCH => [
                AbstractDAO::SEARCH_TERM               => $term,
                AbstractDAO::SEARCH_LIMIT              => 0,
                AbstractDAO::SEARCH_RETURN_RAW_ENTRIES => false,
                AbstractDAO::SEARCH_STARTS_WITH_ONLY   => $startsWithOnly,
                ISearch::PATH => $path,
                IFileSearch::P4_SEARCH_HOST => ConfigManager::getValue(
                    $config, ConfigManager::SEARCH_P4_SEARCH_HOST, false
                ),
                IFileSearch::P4_SEARCH_API_PATH => ConfigManager::getValue(
                    $config, ConfigManager::SEARCH_P4_SEARCH_API_PATH
                ),
            ]
        ];

        $fileSearchService = $this->services->get(IFileSearch::FILE_SEARCH_SERVICE);
        return $fileSearchService->search($context, $options[AbstractDAO::FETCH_SEARCH]);
    }

    /**
     * Limit the results according to the order in which the the models were listed in the context
     * If a limit is specified, distribute the results as evenly as possible across the models and
     * add it to the data array as a key-value pair.
     *
     * @param array   $data    data to be ordered
     * @param int     $limit   total number of results returned
     */
    protected function limitResults(array &$data, int $limit)
    {
        // If there is not a positive limit, we don't limit results and this is a noop
        if ($limit <= 0) {
            return;
        }

        // Create some objects to keep track of state during the loop
        $metrics = [];
        foreach ($data as $model => $matches) {
            $metrics[$model] = ['numMatches' => count($matches[SearchApi::RESULTS]), 'limit' => 0, 'done' => false];
        }

        // The amount of remaining limit to allocate
        $remainingLimit = $limit;
        // The number of models having more records than their allotted limit
        $numNeedingMoreLimit = count($metrics);

        // iteratively allocate the remaining limit until there's no more remaining limit or all models have allocations
        while ($remainingLimit > 0 && $numNeedingMoreLimit > 0) {
            $allocations = array_reverse($this->getAllocations($remainingLimit, $numNeedingMoreLimit));
            foreach ($metrics as $model => $metric) {
                if ($metric['done'] === false) {
                    $allocation       = array_pop($allocations);
                    $metric['limit'] += $allocation;
                    if ($metric['numMatches'] <= $metric['limit']) {
                        // The model has all its matches covered by its limit allotment
                        // and will only reduce the remaining limit by its number of matchhes
                        $remainingLimit -= $metric['numMatches'];
                        --$numNeedingMoreLimit;
                        $metric['done'] = true;
                    } else {
                        // The model will use all the allotment and reduce the remaining limit by that much
                        $remainingLimit -= $allocation;
                    }
                }
                $metrics[$model] = $metric;
            }
        }

        // Apply the limits
        foreach ($data as $model => $matches) {
            $data[$model][SearchApi::RESULTS] =
                array_slice($data[$model][SearchApi::RESULTS], 0, $metrics[$model]['limit']);
        }

        // Add the limit to the data returned
        $data[ISearch::LIMIT] = $limit;
    }

    /**
     * Distributes an integer into an array of integer addends as equally as possible.
     * This algorithm divides the numerator by the denominator and if there is a remainder,
     * distributes it from left to right.
     *
     * @param int   $numerator     number to be divided
     * @param int   $denominator   number of elements in the resulting array
     *
     * @return array
     */
    protected function getAllocations($numerator, $denominator)
    {
        $allocations = [];
        $intDiv      = intdiv($numerator, $denominator);
        $rem         = $numerator % $denominator;
        for ($i = 0; $i < $denominator; $i++) {
            $allocations[$i] = $intDiv;
        }
        for ($i = 0; $i < $rem; $i++) {
            ++$allocations[$i];
        }
        return $allocations;
    }
}
