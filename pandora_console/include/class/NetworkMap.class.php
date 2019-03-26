<?php
/**
 * Class to manage networkmaps in Pandora FMS
 *
 * @category   Class
 * @package    Pandora FMS
 * @subpackage NetworkMap manager
 * @version    1.0.0
 * @license    See below
 *
 *    ______                 ___                    _______ _______ ________
 *   |   __ \.-----.--.--.--|  |.-----.----.-----. |    ___|   |   |     __|
 *  |    __/|  _  |     |  _  ||  _  |   _|  _  | |    ___|       |__     |
 * |___|   |___._|__|__|_____||_____|__| |___._| |___|   |__|_|__|_______|
 *
 * ============================================================================
 * Copyright (c) 2005-2019 Artica Soluciones Tecnologicas
 * Please see http://pandorafms.org for full contribution list
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation for version 2.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * ============================================================================
 */

// Begin.
global $config;

require_once $config['homedir'].'/include/functions_networkmap.php';
enterprise_include_once('include/functions_networkmap.php');
enterprise_include_once('include/functions_discovery.php');

// Avoid node overlapping.
define('GRAPHVIZ_RADIUS_CONVERSION_FACTOR', 20);
define('MAP_X_CORRECTION', 600);
define('MAP_Y_CORRECTION', 150);


/**
 * Manage networkmaps in Pandora FMS.
 *
 * General steps:
 *   Generate a list of nodes.
 *   For each node, calculate relationship and add several 'module' nodes
 *     representing interface nodes.
 *   Once the base arrays are formed (nodes and relations), this class
 *   calls graphviz to calculate X,Y positions for given nodes.
 *   Translates node - relationship - positioning data into processed
 *   'nodes_and_relations'.
 *   When printMap is called. Several information is sent to browser:
 *    - Base DOM items where place target map.
 *    - JS controllers.
 *    - Data translated to JSON format.
 *    - Interface layer.
 *
 * Basic parameters for NetworkMap class constructor:
 *
 * nodes => is really 'rawNodes' here you put an array like follows:
 * nodes => [
 *   'some_id' => [
 *       'type' => NODE_GENERIC/NODE_PANDORA/NODE_MODULE/NODE_AGENT,
 *                 behaviour and fields to be used are different depending
 *                 on the type.
 *       'id_agente' => 'some_id',
 *       'status' => 'agent status',
 *       'id_parent' => 'target parent to match in map, its no need to use the
 *                       real parent but must match some_id'
 *       'id_node' => incremental id (0,1,2,3...),
 *       'image' => relative path to image to use,
 *       'label' => label to use (in NODE_GENERIC)
 *   ]
 * ]
 *
 * Tooltipster support: Keep in mind, using tooltipster behaviour
 * of map changes.
 *
 *   Sample usage:
 *
 * <code>
 *
 *    $map_manager = new NetworkMap(
 *        [
 *            'nodes'           => vmware_get_nodes($show_vms, $show_ds, $show_esx),
 *            'pure'            => 1,
 *            'use_tooltipster' => 1,
 *            'tooltip_params'  => [
 *                'page'           => 'operation/agentes/ver_agente',
 *                'get_agent_json' => 1,
 *            ],
 *        ]
 *    );
 *
 *    $map_manager->printMap();
 *
 * </code>
 */
class NetworkMap
{

    /**
     * Target map Id, from tmap. If the maps is being simulated
     * then the idMap value will be uniqid.
     *
     * @var integer
     */
    public $idMap;

    /**
     * Content of tmap. Map definition. If the map is being simulated
     * then defaults to constructor received parameters.
     *
     * @var array
     */
    public $map;

    /**
     * Data origin, network.
     *
     * @var string
     */
    public $network;

    /**
     * Data origin, group id.
     *
     * @var integer
     */
    public $idGroup;

    /**
     * Data origin, Discovery task.
     *
     * @var integer
     */
    public $idTask;

    /**
     * Graph definition. Previously was 'nodes_and_relationships'
     * Is the data format before be translated to JS variables.
     *
     * @var array
     */
    public $graph;

    /**
     * Dot string with graph definition.
     * Its contents will be send to graphviz to calculate node positions.
     *
     * @var string
     */
    public $dotGraph;

    /**
     * Node list processed by NetworkMap class.
     *
     * @var array
     */
    public $nodes;

    /**
     * Node list RAW.
     * A simple list of nodes, could content information of agents, modules...
     * Is the 'raw' information.
     *
     * @var array
     */
    public $rawNodes;

    /**
     * Useful to translate id_node to id_agent or id_module.
     * Maps built nodes to original node information (agents, modules).
     *
     * @var array
     */
    public $nodeMapping;

    /**
     * Relationship map.
     * Each element contents:
     *    id_parent
     *    id_child
     *    parent_type
     *    child_type
     *    id_parent_source_data (from $this->nodes)
     *    id_child_source_data (from $this->nodes)
     *
     * @var array
     */
    public $relations;

    /**
     * Include a Pandora (or vendor) node or not.
     *
     * @var integer
     */
    public $noPandoraNode;

    /**
     * Use tooltipster interface instead of standard.
     *
     * @var boolean
     */
    public $useTooltipster;

    /**
     * Options used in AJAX call while using tooltipster.
     *
     * @var array
     */
    public $tooltipParams;

    /**
     * Defines a custom method to parse Graphviz output and generate Graph.
     * Function pointer.
     *
     * @var string
     */
    public $customParser;

    /**
     * Defines arguments to be passed to $customParser.
     * If is not defined, default arguments will be used.
     *
     * @var array
     */
    public $customParserArgs;

    /**
     * If using a custom parser, fallback to default parser while
     * found exceptions.
     *
     * @var boolean
     */
    public $fallbackDefaultParser;

    /**
     * Array of map options. Because how is built, the structure matches
     * with tmap definition, where map_filter is the json-extracted data.
     * Duplicate options appears since tmap stores information in different
     * ways (simplifies process).
     * If an idMap is defined, map is loaded into this structure and used along
     * the class.
     *   generation_method
     *   simple
     *   font_size
     *   nooverlap
     *   z_dash
     *   ranksep
     *   center
     *   regen
     *   pure
     *   show_snmp_modules
     *   cut_names
     *   relative
     *   text_filter
     *   dont_show_subgroups
     *   strict_user
     *   size_canvas
     *   old_mode
     *   map_filter (array)
     *       dont_show_subgroups
     *       node_radius
     *       x_offs
     *       y_offs
     *       z_dash
     *       node_sep
     *       rank_sep
     *       mindist
     *       kval
     *
     * @var array
     */
    public $mapOptions;

    /**
     * Filter (command) to use to calculate node positions.
     *
     * @var string
     */
    private $filter;


    /**
     * Base constructor.
     *
     * @param mixed $options Could define in array as:
     *   id_map => target discovery task id.
     *   id_group => target group.
     *   network => target CIDR.
     *   graph => target graph (already built).
     *   nodes => target agents or nodes.
     *   relations => target array of relationships.
     *   mode => simple (0) or advanced (1).
     *   map_options => Map options.
     *
     * @return object New networkmap manager.
     */
    public function __construct($options=false)
    {
        global $config;

        // Default mapOptions values.
        // Defines the command to generate positions.
        $this->mapOptions['generation_method'] = LAYOUT_SPRING1;
        $this->mapOptions['width'] = $config['networkmap_max_width'];
        $this->mapOptions['height'] = $config['networkmap_max_width'];
        $this->mapOptions['simple'] = 0;
        $this->mapOptions['font_size'] = 12;
        $this->mapOptions['nooverlap'] = 1;
        $this->mapOptions['z_dash'] = 0.5;
        $this->mapOptions['center'] = 0;
        $this->mapOptions['regen'] = 0;
        $this->mapOptions['pure'] = 0;
        $this->mapOptions['show_snmp_modules'] = false;
        $this->mapOptions['cut_names'] = false;
        $this->mapOptions['relative'] = true;
        $this->mapOptions['text_filter'] = '';
        $this->mapOptions['dont_show_subgroups'] = false;
        $this->mapOptions['strict_user'] = false;
        $this->mapOptions['size_canvas'] = 0;
        $this->mapOptions['old_mode'] = false;
        $this->mapOptions['map_filter'] = [
            'dont_show_subgroups' => 0,
            'node_radius'         => 40,
            'x_offs'              => 0,
            'y_offs'              => 0,
            'z_dash'              => 0.5,
            'node_sep'            => 5,
            'rank_sep'            => 5,
            'mindist'             => 1,
            'kval'                => 0.1,
        ];

        if (is_array($options)) {
            // Previously nodes_and_relations.
            if (isset($options['graph'])) {
                $this->graph = $options['graph'];
            }

            // String dotmap.
            if (isset($options['dot_graph'])) {
                $this->dotGraph = $options['dot_graph'];
            }

            // Array of nodes, agents, virtual, etc.
            if (isset($options['nodes'])) {
                $this->rawNodes = $options['nodes'];
            }

            // Array of relations.
            if (isset($options['relations'])) {
                $this->relations = $options['relations'];
            }

            // User interface type. Simple or advanced.
            if (isset($options['mode'])) {
                $this->mode = $options['mode'];
            }

            // Show interface elements or dashboard style.
            if (isset($options['pure'])) {
                $this->mapOptions['pure'] = $options['pure'];
            }

            if (isset($options['no_pandora_node'])) {
                $this->noPandoraNode = $options['no_pandora_node'];
            }

            // Use a custom parser.
            if (isset($options['custom_parser'])) {
                $this->customParser = $options['custom_parser'];
            }

            // Custom parser arguments.
            if (isset($options['custom_parser_args'])) {
                if (is_array($options['custom_parser_args'])) {
                    foreach ($options['custom_parser_args'] as $k) {
                        $this->customParserArgs[] = $k;
                    }
                } else {
                    $this->customParserArgs = $options['custom_parser_args'];
                }
            }

            // Fallback to default parser.
            if (isset($options['fallback_to_default_parser'])) {
                $this->fallbackDefaultParser = $options['fallback_to_default_parser'];
            }

            if (isset($options['use_tooltipster'])) {
                $this->useTooltipster = $options['use_tooltipster'];
            }

            if (is_array($options['tooltip_params'])) {
                foreach ($options['tooltip_params'] as $k => $v) {
                    $this->tooltipParams[$k] = $v;
                }
            }

            // Map options, check default values above.
            // This is only used while generating new maps using
            // (generateDotGraph).
            if (isset($options['map_options'])
                && is_array($options['map_options'])
            ) {
                foreach ($options['map_options'] as $k => $v) {
                    if ($k == 'map_filter' && is_array($v)) {
                        foreach ($v as $kc => $vc) {
                            $this->mapOptions['map_filter'][$kc] = $vc;
                        }
                    } else {
                        $this->mapOptions[$k] = $v;
                    }
                }
            }

            // Load from tmap.
            if ($options['id_map']) {
                $this->idMap = $options['id_map'];
                // Update nodes and relations.
                $this->loadMap();

                if (empty($this->nodes)
                    && empty($this->relations)
                ) {
                    $this->createMap();
                }
            } else {
                // Generate from group, task or network.
                if ($options['id_group']) {
                    $this->idGroup = $options['id_group'];
                }

                if ($options['id_task']) {
                    $this->idTask = $options['id_task'];
                }

                if ($options['network']) {
                    $this->network = $options['network'];
                }

                $this->createMap();
            }
        }

        return $this;

    }


    /**
     * Creates a new map based on a target.
     *
     * Target is specified from constructor arguments.
     *   options:
     *    - id_task  => create a map from task.
     *    - id_group => create a map from group.
     *    - network  => create a map from network.
     *
     * @return void
     */
    public function createMap()
    {
        // If exists, load from DB.
        if ($this->idMap) {
            $this->loadMap();

            return;
        }

        // Simulated map.
        $this->idMap = uniqid();
        // No tmap definition. Paint data.
        if ($this->idTask) {
            $recon_task = db_get_row_filter(
                'trecon_task',
                ['id_rt' => $this->idTask]
            );
            $this->network = $recon_task['subnet'];
        }

        // Simulate map entry.
        $this->map = [
            'id'                 => $this->idMap,
            '__simulated'        => 1,
            'background'         => '',
            'background_options' => 0,
            'source_period'      => 60,
            'filter'             => $this->mapOptions['map_filter'],
            'width'              => $config['networkmap_max_width'],
            'height'             => $config['networkmap_max_width'],
            'center_x'           => 0,
            'center_y'           => 0,
        ];

        if (isset($this->mapOptions['generation_method']) === false) {
            $this->mapOptions['generation_method'] = LAYOUT_SPRING1;
        }

        // Load filter.
        $this->loadFilter();

        // Will be stored in $this->graph.
        $this->generateNetworkMap();

    }


    /**
     * Update filter and layout based on generation_method selected.
     *
     * @return boolean True or false.
     */
    private function loadFilter()
    {
        if (is_array($this->mapOptions) === false) {
            return false;
        }

        switch ($this->mapOptions['generation_method']) {
            case LAYOUT_CIRCULAR:
                $this->filter = 'circo';
                $this->mapOptions['layout'] = 'circular';
            break;

            case LAYOUT_FLAT:
                   $this->filter = 'dot';
                   $this->mapOptions['layout'] = 'flat';
            break;

            case LAYOUT_RADIAL:
                   $this->filter = 'twopi';
                   $this->mapOptions['layout'] = 'radial';
            break;

            case LAYOUT_SPRING1:
            default:
                   $this->filter = 'neato';
                   $this->mapOptions['layout'] = 'spring1';
            break;

            case LAYOUT_SPRING2:
                   $this->filter = 'fdp';
                   $this->mapOptions['layout'] = 'spring2';
            break;
        }

        return true;
    }


    /**
     * Loads a map from a target map ID.
     *
     * @return void.
     */
    public function loadMap()
    {
        if ($this->map) {
            // Already loaded.
            return;
        }

        if ($this->idMap) {
            $this->map = db_get_row('tmap', 'id', $this->idMap);

            $this->mapOptions['map_filter'] = json_decode(
                $this->map['filter'],
                true
            );

            foreach ($this->map as $k => $v) {
                $this->mapOptions[$k] = $v;
            }

            // Load filter.
            $this->loadFilter();

            // Retrieve data origin.
            $this->network = null;
            $this->idTask = null;
            $this->idGroup = $this->map['id_group'];

            switch ($this->map['source']) {
                case SOURCE_TASK:
                    $this->idTask = $this->map['source_data'];
                break;

                case SOURCE_NETWORK:
                    $this->network = $this->map['source_data'];
                break;

                case SOURCE_GROUP:
                    // Already load.
                default:
                    // Ignore.
                break;
            }

            if ($this->idTask) {
                $recon_task = db_get_row_filter(
                    'trecon_task',
                    ['id_rt' => $this->idTask]
                );
                $this->network = $recon_task['subnet'];
            }

            // Retrieve or update nodes and relations.
            $this->getNodes();
            $this->getRelations();

            // Nodes and relations will be stored in $this->graph.
            $this->loadGraph();
        }
    }


    /**
     * Retrieves node information using id_node as mapping instead element id.
     *
     * @param integer $id_node Target node.
     * @param string  $field   Field to retrieve, if null, all are return.
     *
     * @return mixed Array (node data) or null if error.
     */
    public function getNodeData(int $id_node, $field=null)
    {
        if (is_array($this->nodes) === false
            || is_array($this->nodeMapping) === false
        ) {
            return null;
        }

        if (is_array($this->nodes[$this->nodeMapping[$id_node]]) === true) {
            if (isset($field) === false) {
                return $this->nodes[$this->nodeMapping[$id_node]];
            } else {
                return $this->nodes[$this->nodeMapping[$id_node]][$field];
            }
        } else {
            return null;
        }
    }


    /**
     * Set nodes.
     *
     * @param array $nodes Nodes definition.
     *
     * @return void
     */
    public function setNodes($nodes)
    {
        $this->nodes = $nodes;
    }


    /**
     * Return nodes of current map.
     *
     * @return array Nodes.
     */
    public function getNodes()
    {
        if ($this->nodes) {
            return $this->nodes;
        }

        if ($this->idMap !== false) {
            if (enterprise_installed()) {
                // Enterprise environment: LOAD.
                $this->nodes = enterprise_hook(
                    'get_nodes_from_db',
                    [$this->idMap]
                );
            }
        }

        return $this->nodes;

    }


    /**
     * Set relations.
     *
     * @param array $relations Relations definition.
     *
     * @return void
     */
    public function setRelations($relations)
    {
        $this->relations = $relations;
    }


    /**
     * Return relations of current map.
     *
     * @return array Relations.
     */
    public function getRelations()
    {
        if ($this->relations) {
            return $this->relations;
        }

        if ($this->idMap !== false) {
            if (enterprise_installed()) {
                $this->relations = enterprise_hook(
                    'get_relations_from_db',
                    [$this->idMap]
                );
            }
        }

        return $this->relations;

    }


    /**
     * Search for nodes in current map definition.
     *
     * @return array Nodes detected, internal variable NOT updated.
     */
    public function calculateNodes()
    {
        global $config;

        // Calculate.
        // Search.
        if (enterprise_installed() && $this->idTask) {
            // Network map, based on discovery task.
            return enterprise_hook(
                'get_discovery_agents',
                [$this->idTask]
            );
        }

        if ($this->network) {
            // Network map, based on direct network.
            $nodes = networkmap_get_nodes_from_ip_mask(
                $this->network
            );
        } else if ($this->mapOptions['map_filter']['empty_map']) {
            // Empty map returns no data.
            $nodes = [];
        } else {
            if ($this->mapOptions['map_filter']['dont_show_subgroups'] === 'true'
                || $this->mapOptions['map_filter']['dont_show_subgroups'] == 1
            ) {
                // Show only current selected group.
                $filter['id_grupo'] = $this->idGroup;
            } else {
                // Show current group and children.
                $childrens = groups_get_childrens($this->idGroup, null, true);
                if (!empty($childrens)) {
                    $childrens = array_keys($childrens);

                    $filter['id_grupo'] = $childrens;
                    $filter['id_grupo'][] = $this->idGroup;
                } else {
                    $filter['id_grupo'] = $this->idGroup;
                }
            }

            // Group map.
            $nodes = agents_get_agents(
                $filter,
                ['*'],
                'AR',
                [
                    'field' => 'id_parent',
                    'order' => 'ASC',
                ]
            );

            if (is_array($nodes)) {
                // Remap ids.
                $nodes = array_reduce(
                    $nodes,
                    function ($carry, $item) {
                        $carry[$item['id_agente']] = $item;
                        return $carry;
                    }
                );
            } else {
                $nodes = [];
            }
        }

        return $nodes;
    }


    /**
     * Search for relations for a given node in current map definition.
     * Use id_parent in custom node definition to create an edge between
     * two nodes.
     *
     * Representation is to => from because from could be equal in multiple
     * edges but no to (1 origin, multiple targets).
     *
     * @param array $id_source Id for source data, agent, module or custom.
     *
     * @return array Relations found for given node.
     */
    public function calculateRelations(
        $id_source
    ) {
        // Calculate.
        $node = $this->nodes[$id_source];
        if (is_array($node) === false) {
            return false;
        }

        $relations = [];
        $i = 0;
        $from_type = NODE_AGENT;
        $to_type = NODE_AGENT;
        switch ($node['node_type']) {
            case NODE_AGENT:
                // Search for agent parent and module relationships.
                $module_relations = modules_get_relations(
                    ['id_agent' => $node['id_agente']]
                );

                if ($module_relations !== false) {
                    // Module relation exist.
                    foreach ($module_relations as $mod_rel) {
                        // Check if target referenced agent is defined in
                        // current map.
                        $agent_a = modules_get_agentmodule_agent(
                            $mod_rel['module_a']
                        );
                        $module_a = $mod_rel['module_a'];
                        $agent_b = modules_get_agentmodule_agent(
                            $mod_rel['module_b']
                        );
                        $module_b = $mod_rel['module_b'];

                        // Calculate target.
                        $module_to = $module_a;
                        $agent_to = $agent_a;
                        $module_from = $module_b;
                        $agent_from = $agent_b;

                        // Module relations does not have from and to,
                        // If current agent_a is current node, reverse relation.
                        if ($agent_a == $node['id_agente']) {
                            $module_to = $module_b;
                            $agent_to = $agent_b;
                            $module_from = $module_a;
                            $agent_from = $agent_a;
                        }

                        $target_node = $this->nodes[NODE_AGENT.'_'.$agent_to];

                        if (isset($target_node) === false) {
                            // Agent is not present in this map.
                            continue;
                        }

                        $rel = [];
                        // Node reference (child).
                        $rel['id_child'] = $node['id_node'];
                        $rel['child_type'] = NODE_MODULE;
                        $rel['id_child_source_data'] = $module_from;
                        $rel['id_child_agent'] = $agent_from;

                        // Node reference (parent).
                        $rel['id_parent'] = $target_node['id_node'];
                        $rel['parent_type'] = NODE_MODULE;
                        $rel['id_parent_source_data'] = $module_to;
                        $rel['id_parent_agent'] = $agent_to;

                        // Store relation.
                        $relations[] = $rel;
                    }
                }

                // Add also parent relationship.
                $parent_id = NODE_AGENT.'_'.$node['id_parent'];

                if ((int) $node['id_parent'] > 0) {
                    $parent_node = $this->nodes[$parent_id]['id_node'];
                }

                // Store relationship.
                if (is_integer($parent_node) && $node['id_parent'] > 0) {
                    $rel = [];

                    // Node reference (parent).
                    $rel['id_parent'] = $parent_node;
                    $rel['parent_type'] = NODE_AGENT;
                    $rel['id_parent_source_data'] = $node['id_parent'];

                    // Node reference (child).
                    $rel['id_child'] = $node['id_node'];
                    $rel['child_type'] = NODE_AGENT;
                    $rel['id_child_source_data'] = $node['id_agente'];

                    // Store relation.
                    $relations[] = $rel;
                }
            break;

            case NODE_MODULE:
                // Search for module relationships.
                $module_relations = modules_get_relations(
                    ['id_module' => $node['id_agente_modulo']]
                );

                if ($module_relations !== false) {
                    // Module relation exist.
                    foreach ($module_relations as $mod_rel) {
                        // Check if target referenced agent is defined in
                        // current map.
                        $agent_a = modules_get_agentmodule_agent(
                            $mod_rel['module_a']
                        );
                        $module_a = $mod_rel['module_a'];
                        $agent_b = modules_get_agentmodule_agent(
                            $mod_rel['module_b']
                        );
                        $module_b = $mod_rel['module_b'];

                        // Calculate target.
                        $module_to = $module_a;
                        $agent_to = $agent_a;
                        $module_from = $module_b;
                        $agent_from = $agent_b;

                        // Module relations does not have from and to,
                        // If current agent_a is current node, reverse relation.
                        if ($agent_a == $node['id_agente']) {
                            $module_to = $module_b;
                            $agent_to = $agent_b;
                            $module_from = $module_a;
                            $agent_from = $agent_a;
                        }

                        $target_node = $this->nodes[NODE_AGENT.'_'.$agent_to];

                        if (isset($target_node) === false) {
                            // Agent is not present in this map.
                            continue;
                        }

                        $rel = [];
                        // Node reference (child).
                        $rel['id_child'] = $node['id_node'];
                        $rel['child_type'] = NODE_MODULE;
                        $rel['id_child_source_data'] = $module_from;
                        $rel['id_child_agent'] = $agent_from;

                        // Node reference (parent).
                        $rel['id_parent'] = $target_node['id_node'];
                        $rel['parent_type'] = NODE_MODULE;
                        $rel['id_parent_source_data'] = $module_to;
                        $rel['id_parent_agent'] = $agent_to;

                        // Store relation.
                        $relations[] = $rel;
                    }
                }
            break;

            case NODE_GENERIC:
                // Handmade ones.
                // Add also parent relationship.
                $parent_id = $node['id_parent'];

                if ((int) $parent_id > 0) {
                    $parent_node = $this->getNodeData(
                        (int) $parent_id,
                        'id_node'
                    );
                }

                // Store relationship.
                if ($parent_node) {
                    $relations[] = [
                        'id_parent'   => $parent_node,
                        'parent_type' => NODE_GENERIC,
                        'id_child'    => $node['id_node'],
                        'child_type'  => NODE_GENERIC,
                    ];
                }
            break;

            case NODE_PANDORA:
            default:
                // Ignore.
            break;
        }

        // Others.
        return $relations;
    }


    /**
     * Generates or loads nodes&relations array from DB.
     * Load, calculates statuses and leave the structure in $this->graph.
     *
     * * Structure generated:
     * Nodes:
     *   id_map.
     *   id.
     *   id_agent.
     *   id_module.
     *   type.
     *   x.
     *   y.
     *   width.
     *   height.
     *   text.
     *   source_data.
     *   style (json).
     *
     * Relations:
     *   id_map.
     *   id_parent.
     *   parent_type.
     *   id_parent_source_data.
     *   id_child.
     *   child_type.
     *   id_child_source_data.
     *   id_parent_agent.
     *   id_child_agent.
     *
     * @return void
     */
    public function loadGraph()
    {
        $nodes = $this->nodes;
        $relations = $this->relations;

        // Generate if there's no data in DB about nodes or relations.
        if (empty($nodes) && empty($relations)) {
            $this->generateNetworkMap();
            return;
        }

        $graph = enterprise_hook(
            'networkmap_load_map',
            [$this]
        );

        if ($graph === ENTERPRISE_NOT_HOOK) {
            // Method not available, regenerate.
            $this->generateNetworkMap();
            return;
        }

        $this->graph = $graph;

    }


    /**
     * Generates a graph definition (header only) for dot graph.
     *
     * @return string Dot graph header.
     */
    public function openDotFile()
    {
        global $config;

        $overlap = 'compress';

        $map_filter = $this->mapOptions['map_filter'];
        $nooverlap = $this->mapOptions['nooverlap'];
        $zoom = $this->mapOptions['zoom'];

        if (isset($this->mapOptions['width'])
            && isset($this->mapOptions['height'])
        ) {
            $size_x = ($this->mapOptions['width'] / 100);
            $size_y = ($this->mapOptions['height'] / 100);
        } else if (isset($config['networkmap_max_width'])) {
            $size_x = ($config['networkmap_max_width'] / 100);
            $size_y = ($size_x * 0.8);
        } else {
            $size_x = 8;
            $size_y = 5.4;
            $size = '';
        }

        if ($zoom > 0) {
            $size_x *= $zoom;
            $size_y *= $zoom;
        }

        $size = $size_x.','.$size_y;

        if ($size_canvas === null) {
            $size = ($this->mapOptions['size_canvas']['x'] / 100);
            $size .= ','.($this->mapOptions['size_canvas']['y'] / 100);
        }

        // Graphviz custom values.
        if (isset($map_filter['node_sep'])) {
            $node_sep = $map_filter['node_sep'];
        } else {
            $node_sep = 0.1;
        }

        if (isset($map_filter['rank_sep'])) {
            $rank_sep = $map_filter['rank_sep'];
        } else {
            if ($layout == 'radial') {
                $rank_sep = 1.0;
            } else {
                $rank_sep = 0.5;
            }
        }

        if (isset($map_filter['mindist'])) {
            $mindist = $map_filter['mindist'];
        } else {
            $mindist = 1.0;
        }

        if (isset($map_filter['kval'])) {
            $kval = $map_filter['kval'];
        } else {
            $kval = 0.1;
        }

        // BEWARE: graphwiz DONT use single ('), you need double (").
        $head = 'graph networkmap { dpi=100; bgcolor="transparent"; labeljust=l; margin=0; pad="0.75,0.75";';
        if ($nooverlap != '') {
            $head .= 'overlap=scale;';
            $head .= 'outputorder=first;';
        }

        if ($layout == 'flat'
            || $layout == 'spring1'
            || $layout == 'spring2'
        ) {
            if ($nooverlap != '') {
                $head .= 'overlap="scalexy";';
            }

            if ($layout == 'flat') {
                $head .= 'ranksep="'.$rank_sep.'";';
            }

            if ($layout == 'spring2') {
                $head .= 'K="'.$kval.'";';
            }
        }

        if ($layout == 'radial') {
            $head .= 'ranksep="'.$rank_sep.'";';
        }

        if ($layout == 'circular') {
            $head .= 'mindist="'.$mindist.'";';
        }

        $head .= 'ratio="fill";';
        $head .= 'root=0;';
        $head .= 'nodesep="'.$node_sep.'";';
        $head .= 'size="'.$size.'";';

        $head .= "\n";

        return $head;
    }


    /**
     * Creates a node in dot format.
     * Requirements:
     *   id_node
     *   id_source
     *   status => defines 'color'
     *   label
     *   image
     *   url
     *
     * @param array $data Node definition.
     *
     * @return string Dot node.
     */
    public function createDotNode($data)
    {
        global $config;

        if (is_array($data) === false) {
            return '';
        }

        $dot_str = '';

        // Color is being printed by D3, not graphviz.
        // Used only for positioning.
        $color = COL_NORMAL;
        $label = $data['label'];
        $url = 'none';
        $parent = $data['parent'];
        $font_size = $this->mapOptions['font_size'];
        $radius = $this->mapOptions['map_filter']['node_radius'];
        $radius /= GRAPHVIZ_RADIUS_CONVERSION_FACTOR;

        if (strlen($label) > 16) {
            $label = ui_print_truncate_text($label, 16, false, true, false);
        }

        // If radius is 0, set to 1 instead.
        if ($radius <= 0) {
            $radius = 1;
        }

        // Simple node always. This kind of node is used only to
        // retrieve X,Y positions from graphviz no for personalization.
        $dot_str = $data['id_node'].' [ parent="'.$data['id_parent'].'"';
        $dot_str .= ', color="'.$color.'", fontsize='.$font_size;
        $dot_str .= ', shape="doublecircle"'.$url_node_link;
        $dot_str .= ', style="filled", fixedsize=true, width='.$radius;
        $dot_str .= ', height='.$radius.', label="'.$label.'"]'."\n";

        return $dot_str;
    }


    /**
     * Avoid multiple connections between two nodes if any of them does not
     * add more information. Prioritize.
     *
     * For instance, if we have module - module relationship and agent - agent
     * discard agent - agent relationship (module - module apports more
     * information).
     *
     * @return void
     */
    public function cleanGraphRelations()
    {
        global $config;

        $relations = $this->graph['relations'];

        $cleaned = [];
        $rel_map = [];

        /*
         * Relation map:
         *   id_child.'_'.id_parent => [
         *     'priority' (0,1)
         *     'relation_index'
         *   ]
         */

        if (is_array($relations)) {
            foreach ($relations as $index => $rel) {
                /*
                 *  AA, AM and MM links management
                 *  Priority:
                 *    1 -> MM (module - module)
                 *    1 -> AM (agent - module)
                 *    0 -> AA (agent - agent)
                 */

                $id_parent = $rel['id_parent'];
                $id_child = $rel['id_child'];
                $rel_type = $rel['child_type'].'_'.$rel['parent_type'];

                $valid = 0;
                $key = -1;
                if ($rel['parent_type'] == NODE_MODULE
                    && $rel['child_type'] == NODE_MODULE
                ) {
                    // Module information available.
                    $priority = 1;
                    $valid = 1;

                    if (is_array($rel_map[$id_child.'_'.$id_parent])) {
                        // Already defined.
                        $key = $id_child.'_'.$id_parent;
                        $data = $rel_map[$id_child.'_'.$id_parent];
                        if ($priority > $data['priority']) {
                            unset($rel[$data['index']]);
                        } else {
                            $valid = 0;
                        }
                    }

                    if (is_array($rel_map[$id_parent.'_'.$id_child])) {
                        // Already defined.
                        $key = $id_parent.'_'.$id_child;
                        $data = $rel_map[$id_parent.'_'.$id_child];
                        if ($priority > $data['priority']) {
                            unset($rel[$data['index']]);
                        } else {
                            $valid = 0;
                        }
                    }

                    if ($valid == 1) {
                        $rel_map[$id_parent.'_'.$id_child] = [
                            'index'    => $index,
                            'priority' => $priority,
                        ];
                    }
                } else if ($rel['parent_type'] == NODE_AGENT
                    && $rel['child_type'] == NODE_AGENT
                ) {
                    // Module information not available.
                    $priority = 0;
                    $valid = 1;

                    if (is_array($rel_map[$id_child.'_'.$id_parent])) {
                        // Already defined.
                        $key = $id_child.'_'.$id_parent;
                        $data = $rel_map[$id_child.'_'.$id_parent];
                        if ($priority > $data['priority']) {
                            unset($rel[$data['index']]);
                        } else {
                            $valid = 0;
                        }
                    }

                    if (is_array($rel_map[$id_parent.'_'.$id_child])) {
                        // Already defined.
                        $key = $id_parent.'_'.$id_child;
                        $data = $rel_map[$id_parent.'_'.$id_child];
                        if ($priority > $data['priority']) {
                            unset($rel[$data['index']]);
                        } else {
                            $valid = 0;
                        }
                    }

                    if ($valid == 1) {
                        $rel_map[$id_parent.'_'.$id_child] = [
                            'index'    => $index,
                            'priority' => $priority,
                        ];
                    }
                } else if ($rel['parent_type'] == NODE_MODULE
                    && $rel['child_type'] == NODE_AGENT
                ) {
                    // Module information not available.
                    $priority = 1;

                    $valid = 1;
                } else if ($rel['parent_type'] == NODE_AGENT
                    && $rel['child_type'] == NODE_MODULE
                ) {
                    // Module information not available.
                    $priority = 1;

                    $valid = 1;
                } else {
                    // Pandora & generic links are always accepted.
                    $valid = 1;
                }

                if ($valid === 1) {
                    if ($rel['id_parent'] != $rel['id_child']) {
                        $cleaned[] = $rel;
                    }
                }
            }
        } else {
            return;
        }

        $this->graph['relations'] = $cleaned;
    }


    /**
     * Internal method to allow developer to compare status from
     * different origins by checking a value.
     *
     * Greater value implies more critical.
     *
     * @param integer $status Status.
     *
     * @return integer Criticity value.
     */
    private static function getStatusNumeric($status)
    {
        if (isset($status) === false) {
            return NO_CRIT;
        }

        switch ($status) {
            case AGENT_MODULE_STATUS_NORMAL:
            case AGENT_STATUS_NORMAL:
            return CRIT_1;

            case AGENT_MODULE_STATUS_NOT_INIT:
            case AGENT_STATUS_NOT_INIT:
            return CRIT_0;

            case AGENT_MODULE_STATUS_CRITICAL_BAD:
            case AGENT_STATUS_CRITICAL:
            return CRIT_4;

            case AGENT_MODULE_STATUS_WARNING:
            case AGENT_STATUS_WARNING:
            return CRIT_3;

            case AGENT_MODULE_STATUS_CRITICAL_ALERT:
            case AGENT_MODULE_STATUS_WARNING_ALERT:
            case AGENT_STATUS_ALERT_FIRED:
            return CRIT_5;

            case AGENT_MODULE_STATUS_UNKNOWN:
            case AGENT_STATUS_UNKNOWN:
            return CRIT_2;

            default:
                // Ignored.
            break;
        }

        return NO_CRIT;
    }


    /**
     * Returns worst status from two received.
     * Agent and module statuses should be identical, unless little differences.
     *
     * @param integer $status_a Status A.
     * @param integer $status_b Status B.
     *
     * @return integer Status A or status B, the worstest one.
     */
    public static function getWorstStatus($status_a, $status_b)
    {
        // Case agent statuses.
        $a = self::getStatusNumeric($status_a);
        $b = self::getStatusNumeric($status_b);

        return ($a > $b) ? $status_a : $status_b;
    }


    /**
     * Returns target color to be used based on the status received.
     *
     * @param integer $status Source information.
     *
     * @return string HTML tag for color.
     */
    public static function getColorByStatus($status)
    {
        if (isset($status) === false) {
            return COL_UNKNOWN;
        }

        switch ($status) {
            case AGENT_MODULE_STATUS_NORMAL:
            case AGENT_STATUS_NORMAL:
            return COL_NORMAL;

            case AGENT_MODULE_STATUS_NOT_INIT:
            case AGENT_STATUS_NOT_INIT:
            return COL_NOTINIT;

            case AGENT_MODULE_STATUS_CRITICAL_BAD:
            case AGENT_STATUS_CRITICAL:
            return COL_CRITICAL;

            case AGENT_MODULE_STATUS_WARNING:
            case AGENT_STATUS_WARNING:
            return COL_WARNING;

            case AGENT_MODULE_STATUS_CRITICAL_ALERT:
            case AGENT_MODULE_STATUS_WARNING_ALERT:
            case AGENT_STATUS_ALERT_FIRED:
            return COL_ALERTFIRED;

            case AGENT_MODULE_STATUS_UNKNOWN:
            case AGENT_STATUS_UNKNOWN:
            return COL_UNKNOWN;

            default:
                // Ignored.
            break;
        }

        return COL_IGNORED;

    }


    /**
     * Translates a standard node into a JS node with following attributes:
     *
     * @param array $nodes Input array (standard nodes structure).
     *   id_map.
     *   id_db.
     *   type.
     *   source_data.
     *   x.
     *   y.
     *   z.
     *   state.
     *   deleted.
     *   style.
     *      shape.
     *      image.
     *      label.
     *      id_agent.
     *      id_networkmap.
     *
     * @return array Object ready to be dump to JS.
     * * Output array (translated):
     *   id.
     *   id_db.
     *   type.
     *   id_agent.
     *   id_module.
     *   fixed.
     *   x.
     *   y.
     *   px.
     *   py.
     *   z.
     *   state.
     *   deleted.
     *   image_url.
     *   image_width.
     *   image_height.
     *   raw_text.
     *   text.
     *   shape.
     *   color.
     *   map_id.
     *   networkmap_id.
     */
    public function nodesToJS($nodes)
    {
        global $config;

        $return = [];
        foreach ($nodes as $node) {
            $item = [];
            $item['id'] = $node['id'];

            if ($node['deleted']) {
                // Skip deleted nodes.
                continue;
            }

            // Id titem.
            if (isset($this->map['__simulated']) === false) {
                $item['id_db'] = $node['id_db'];
            } else {
                $item['id_db'] = (int) $node['id'];
            }

            // Get source data.
            $source_data = $this->getNodeData($node['id']);

            if (is_array($node['style']) === false) {
                $node['style'] = json_decode($node['style'], true);
            }

            $item['type'] = $node['type'];
            $item['fixed'] = true;
            $item['x'] = (int) $node['x'];
            $item['y'] = (int) $node['y'];
            $item['z'] = (int) $node['z'];

            // X,Y aliases for D3.
            $item['px'] = $item['x'];
            $item['py'] = $item['y'];

            // Status represents the status of the node (critical, warning...).
            // State represents state of node in map (in holding_area or not).
            $item['state'] = $node['state'];
            $item['deleted'] = $node['deleted'];

            // Node color.
            $item['color'] = self::getColorByStatus($source_data['status']);
            switch ($node['type']) {
                case NODE_AGENT:
                    $item['id_agent'] = $node['source_data'];
                break;

                case NODE_MODULE:
                    $item['id_module'] = $node['source_data'];
                break;

                case NODE_PANDORA:
                    $item['color'] = COL_IGNORED;
                    $node['style']['image'] = ui_get_logo_to_center_networkmap();
                break;

                case NODE_GENERIC:
                default:
                    foreach ($source_data as $k => $v) {
                        $node[$k] = $v;
                    }

                    $node['style']['label'] = $node['name'];
                    $node['style']['shape'] = 'circle';
                    $item['color'] = self::getColorByStatus($node['status']);
                break;
            }

            // Calculate values.
            // 40 => DEFAULT NODE RADIUS.
            // 30 => alignment factor.
            $holding_area_max_y = ($this->mapOptions['height'] + 30 + $this->mapOptions['map_filter']['node_radius'] * 2 - $this->mapOptions['map_filter']['holding_area'][1] + 10 * $this->mapOptions['map_filter']['node_radius']);

            // Update position if node must be stored in holding_area.
            if ($item['state'] == 'holding_area') {
                $holding_area_x = ($this->mapOptions['width'] + 30 + $this->mapOptions['map_filter']['node_radius'] * 2 - $this->mapOptions['map_filter']['holding_area'][0] + ($count_item_holding_area % 11) * $this->mapOptions['map_filter']['node_radius']);
                $holding_area_y = ($this->mapOptions['height'] + 30 + $this->mapOptions['map_filter']['node_radius'] * 2 - $this->mapOptions['map_filter']['holding_area'][1] + (int) (($count_item_holding_area / 11)) * $this->mapOptions['map_filter']['node_radius']);

                // Keep holding area nodes in holding area.
                if ($holding_area_max_y <= $holding_area_y) {
                    $holding_area_y = $holding_area_max_y;
                }

                $item['x'] = $holding_area_x;
                $item['y'] = $holding_area_y;

                // Increment for the next node in holding area.
                $count_item_holding_area++;
            }

            // Node image.
            $item['image_url'] = '';
            $item['image_width'] = 0;
            $item['image_height'] = 0;
            if (empty($node['style']['image']) === false) {
                $item['image_url'] = ui_get_full_url(
                    $node['style']['image']
                );
                $image_size = getimagesize(
                    $config['homedir'].'/'.$node['style']['image']
                );
                $item['image_width'] = (int) $image_size[0];
                $item['image_height'] = (int) $image_size[1];
            }

            $item['raw_text'] = $node['style']['label'];
            $item['text'] = io_safe_output($node['style']['label']);
            $item['shape'] = $node['style']['shape'];
            $item['map_id'] = $node['id_map'];
            if (!isset($node['style']['id_networkmap'])
                || $node['style']['id_networkmap'] == ''
                || $node['style']['id_networkmap'] == 0
            ) {
                $item['networkmap_id'] = 0;
            } else {
                $item['networkmap_id'] = $node['style']['id_networkmap'];
            }

            // XXX: Compatibility with Tooltipster - Simple map controller.
            if ($this->useTooltipster) {
                $item['label'] = $item['text'];
                $item['image'] = $item['image_url'];
                $item['image_height'] = 52;
                $item['image_width'] = 52;
                $item['width'] = $this->mapOptions['map_filter']['node_radius'];
                $item['height'] = $this->mapOptions['map_filter']['node_radius'];
            }

            $return[] = $item;
        }

        return $return;
    }


    /**
     * Transforms an edge relationship into a JS array to be dumped.
     * Sets fields like status, link color and updates some internal identifiers
     * used  by JS frontend.
     *
     * @param array $edges Edges information in array of following items.
     *
     * * Input structure:
     *   id_map.
     *   id_parent.
     *   parent_type.
     *   id_parent_source_data.
     *   id_child.
     *   child_type.
     *   id_child_source_data.
     *   id_parent_agent.
     *   id_child_agent.
     *
     * @return array Edge translated to JS object.
     *
     * * Output structure:
     *   arrow_start.
     *   arrow_end.
     *   status_start.
     *   status_end.
     *   id_module_start.
     *   id_agent_start.
     *   id_module_end.
     *   id_agent_end.
     *   link_color.
     *   target.
     *   source.
     *   deleted.
     *   target_id_db.
     *   source_id_db.
     *   text_start.
     *   text_end.
     */
    public function edgeToJS($edges)
    {
        $return = [];
        // JS edge pseudo identificator.
        $i = 0;
        foreach ($edges as $rel) {
            $item = [];

            // Simulated index.
            $item['id_db'] = $i;
            $item['deleted'] = 0;

            // Else load.
            if (isset($this->map['__simulated']) === false) {
                $item['id_db'] = $rel['id_db'];
                $item['deleted'] = $rel['deleted'];
                $item['target_id_db'] = $this->getNodeData(
                    $rel['id_parent'],
                    'id_db'
                );
                $item['source_id_db'] = $this->getNodeData(
                    $rel['id_child'],
                    'id_db'
                );
            }

            if ($item['deleted']) {
                // Relation is deleted. Avoid.
                continue;
            }

            // Set relationship as 'agent' by default.
            // Generic and Pandora nodes simulates agent relationships.
            $item['arrow_start'] = 'agent';
            $item['arrow_end'] = 'agent';
            $item['source'] = $rel['id_parent'];
            $item['target'] = $rel['id_child'];
            $item['id_agent_start'] = $rel['id_child_agent'];
            $item['id_agent_end'] = $rel['id_parent_agent'];

            if ($rel['parent_type'] == NODE_MODULE) {
                $item['arrow_start'] = 'module';
                $item['id_module_start'] = $rel['id_parent_source_data'];
                $item['status_start'] = modules_get_agentmodule_status(
                    $item['id_module_start']
                );

                // Extract interface name to be placed on edge.
                $text = modules_get_agentmodule_name(
                    (int) $item['id_module_start']
                );
                if (preg_match(
                    '/(.+)_ifOperStatus$/',
                    (string) $text,
                    $matches
                )
                ) {
                    if ($matches[1]) {
                        $item['text_start'] = io_safe_output($matches[1]);
                    }
                }
            }

            if ($rel['child_type'] == NODE_MODULE) {
                $item['arrow_end'] = 'module';
                $item['id_module_end'] = $rel['id_child_source_data'];
                $item['status_end'] = modules_get_agentmodule_status(
                    $item['id_module_end']
                );

                // Extract interface name to be placed on edge.
                $text = modules_get_agentmodule_name(
                    (int) $item['id_module_end']
                );
                if (preg_match(
                    '/(.+)_ifOperStatus$/',
                    (string) $text,
                    $matches
                )
                ) {
                    if ($matches[1]) {
                        $item['text_end'] = io_safe_output($matches[1]);
                    }
                }
            }

            if (isset($rel['text_start']) && !empty($rel['text_start'])) {
                // Direct text_start definition.
                $item['text_start'] = $rel['text_start'];
            }

            if (isset($rel['text_end']) && !empty($rel['text_end'])) {
                // Direct text_end definition.
                $item['text_end'] = $rel['text_end'];
            }

            if (isset($rel['link_color']) && !empty($rel['link_color'])) {
                // Direct color definition.
                $item['link_color'] = $rel['link_color'];
            } else {
                // Use worst case to set link color.
                $item['link_color'] = self::getColorByStatus(
                    self::getWorstStatus(
                        $item['status_start'],
                        $item['status_end']
                    )
                );
            }

            // XXX: Compatibility with Tooltipster - Simple map controller.
            if ($this->useTooltipster) {
                $item['orig'] = $rel['id_parent'];
                $item['dest'] = $rel['id_child'];
            }

            // Set direct values.
            $item['id'] = $i++;

            $return[] = $item;
        }

        return $return;
    }


    /**
     * Creates an edge in dot format.
     * Requirements:
     *   from
     *   to
     *
     * @param array $data Edge content.
     *
     * @return string Dot code for given edge.
     */
    public function createDotEdge($data)
    {
        if (is_array($data) === false) {
            return '';
        }

        if (!isset($data['from']) || !isset($data['to'])) {
            return '';
        }

        $edge = "\n".$data['from'].' -- '.$data['to'];
        $edge .= '[len='.$this->mapOptions['map_filter']['node_sep'];
        $edge .= ', color="#BDBDBD", headclip=false, tailclip=false,';
        $edge .= ' edgeURL=""];'."\n";

        return $edge;
    }


    /**
     * Returns dot file end string.
     *
     * @return string Dot file end string.
     */
    public function closeDotFile()
    {
        return '}';
    }


    /**
     * Generate a graphviz string structure to be used later.
     *
     * Usage:
     *  To create a new handmade graph:
     *    Define node struture
     *      key => node source data (agent/module row or custom)
     *
     * Minimum required fields in array:
     *      label
     *      status
     *      id
     *
     * @param array $nodes Generate dotgraph using defined nodes.
     *
     * @return void
     */
    public function generateDotGraph($nodes=false)
    {
        if (!isset($this->dotGraph)) {
            // Generate dot file.
            $this->nodes = [];
            $edges = [];
            $graph = '';

            if ($nodes === false) {
                if (isset($this->rawNodes)) {
                    $nodes = $this->rawNodes;
                } else {
                    // Search for nodes.
                    $nodes = $this->calculateNodes();
                }
            }

            // Search for relations.
            // Build dot structure.
            // Open Graph.
            $graph = $this->openDotFile();

            if (!$this->noPandoraNode) {
                // Create empty pandora node to link orphans.
                $this->nodes[0] = [
                    'label'            => get_product_name(),
                    'id_node'          => 0,
                    'id_agente'        => 0,
                    'id_agente_modulo' => 0,
                    'node_type'        => NODE_PANDORA,
                ];

                $this->nodeMapping[0] = 0;

                $graph .= $this->createDotNode(
                    $this->nodes[0]
                );
                $i = 1;
            } else {
                $i = 0;
            }

            // Create dot nodes.
            $orphans = [];
            foreach ($nodes as $k => $node) {
                if ((isset($node['type']) && $node['type'] == NODE_AGENT
                    || isset($node['type']) && $node['type'] == NODE_MODULE)
                    || (isset($node['type']) === false
                    && isset($node['id_agente']) === true
                    && $node['id_agente'] > 0)
                ) {
                    // Origin is agent or module.
                    if (isset($node['type']) && $node['type'] == NODE_MODULE
                        || (isset($node['type']) === false
                        && isset($node['id_agente_modulo']) === true
                        && $node['id_agente_modulo'] > 0)
                    ) {
                        $k = NODE_MODULE.'_'.$k;
                        // Origin is module.
                        $id_source = $node['id_agente_modulo'];
                        $label = io_safe_output($node['nombre']);
                        $status = modules_get_agentmodule_status($node);
                        $this->nodes[$k]['node_type'] = NODE_MODULE;

                        $url = 'index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente='.$node['id_agente'];
                        $url_tooltip = 'ajax.php?page=operation/agentes/ver_agente&get_agentmodule_status_tooltip=1&id_module='.$node['id_agente_modulo'];
                    } else {
                        // Origin is agent.
                        $k = NODE_AGENT.'_'.$k;
                        $id_source = $node['id_agente'];
                        $label = io_safe_output($node['alias']);
                        $status = agents_get_status_from_counts($node);
                        $this->nodes[$k]['node_type'] = NODE_AGENT;

                        $url = 'index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente='.$node['id_agente'];
                        $url_tooltip = 'ajax.php?page=operation/agentes/ver_agente&get_agent_status_tooltip=1&id_agent='.$node['id_agente'];
                    }
                } else {
                    // Handmade node.
                    // Store user node definitions.
                    $k = NODE_GENERIC.'_'.$k;
                    $id_source = $node['id'];
                    $label = $node['label'];
                    $status = $node['status'];
                    $this->nodes[$k]['node_type'] = NODE_GENERIC;
                    // In handmade nodes, edges are defined by using id_parent
                    // Referencing target parent 'id'.
                    $this->nodes[$k]['id_parent'] = $node['id_parent'];
                    $this->nodes[$k]['width'] = $node['width'];
                    $this->nodes[$k]['height'] = $node['height'];
                    $this->nodes[$k]['id_source'] = $node['id_source'];
                    $this->nodes[$k]['shape'] = $node['shape'];
                    $url = $this->node['url'];
                    $url_tooltip = $this->node['url_tooltip'];
                }

                $this->nodes[$k]['url'] = $url;
                $this->nodes[$k]['url_tooltip'] = $url_tooltip;

                // Fullfill data.
                // If url is defined in node will be overwritten.
                foreach ($node as $key => $value) {
                    $this->nodes[$k][$key] = $value;
                }

                $graph .= $this->createDotNode(
                    [
                        'id_node'   => $i,
                        'id_source' => $id_source,
                        'label'     => $label,
                        'image'     => null,
                    ]
                );

                // Keep reverse reference.
                $this->nodeMapping[$i] = $k;
                $this->nodes[$k]['id_source_data'] = $id_source;
                $this->nodes[$k]['id_node'] = $i;
                $this->nodes[$k]['status'] = $status;

                // Increase for next node.
                $i++;
            }

            if (!$this->relations) {
                // Search for relations.
                foreach ($this->nodes as $k => $item) {
                    $target = $this->calculateRelations($k);

                    // Adopt all orphan nodes but pandora one.
                    if (empty($target)) {
                        if (isset($this->noPandoraNode) === false
                            || $this->noPandoraNode == false
                        ) {
                            if ($item['id_node'] != 0) {
                                $rel = [];
                                $rel['id_parent'] = 0;
                                $rel['id_child'] = $item['id_node'];
                                $rel['parent_type'] = NODE_PANDORA;
                                $rel['child_type'] = $item['node_type'];
                                $rel['id_child_source_data'] = $item['id_source_data'];

                                $orphans[] = $rel;
                            }
                        }
                    } else {
                        // Flattern edges.
                        foreach ($target as $rel) {
                            $edges[] = $rel;
                        }
                    }
                }
            } else {
                $edges = $this->relations;
            }

            if (is_array($edges)) {
                foreach ($edges as $rel) {
                    $graph .= $this->createDotEdge(
                        [
                            'to'   => $rel['id_child'],
                            'from' => $rel['id_parent'],
                        ]
                    );
                }
            } else {
                $edges = [];
            }

            if (isset($this->noPandoraNode) === false
                || $this->noPandoraNode == false
            ) {
                // Add missed edges.
                foreach ($orphans as $rel) {
                    $graph .= $this->createDotEdge(
                        [
                            'from' => $rel['id_child'],
                            'to'   => $rel['id_parent'],
                        ]
                    );
                }

                // Store relationships.
                $this->relations = array_merge($edges, $orphans);
            } else {
                $this->relations = $edges;
            }

            // Close dot file.
            $graph .= $this->closeDotFile();
            $this->dotGraph = $graph;
        }
    }


    /**
     * Extracts node coordinates and relationships built by graphviz.
     *
     * @param string $graphviz_file Graphviz output file path.
     *
     * @return mixed Nodes and relations if success. False if not.
     */
    private function parseGraphvizMapFile($graphviz_file)
    {
        global $config;

        if (isset($graphviz_file) === false
            || is_file($graphviz_file) === false
        ) {
            return false;
        }

        $content = file($graphviz_file);

        $nodes = [];
        $relations = [];
        foreach ($content as $key => $line) {
            // Reduce blank spaces.
            $line = preg_replace('/\ +/', ' ', $line);

            if (preg_match('/^graph.*$/', $line) != 0) {
                // Graph definition.
                $fields = explode(' ', $line);
                $this->map['width'] = ($fields[2] * 10 * GRAPHVIZ_RADIUS_CONVERSION_FACTOR);
                $this->map['height'] = ($fields[3] * 10 * GRAPHVIZ_RADIUS_CONVERSION_FACTOR);

                if ($this->map['width'] > $config['networkmap_max_width']) {
                    $this->map['width'] = $config['networkmap_max_width'];
                }

                if ($this->map['height'] > $config['networkmap_max_width']) {
                    $this->map['height'] = $config['networkmap_max_width'];
                }
            } else if (preg_match('/^node.*$/', $line) != 0) {
                // Node.
                $fields = explode(' ', $line);
                $id = $fields[1];
                $nodes[$id]['x'] = (($fields[2] * $this->mapOptions['map_filter']['node_radius']) - $this->mapOptions['map_filter']['rank_sep'] * GRAPHVIZ_RADIUS_CONVERSION_FACTOR);
                $nodes[$id]['y'] = (($fields[3] * $this->mapOptions['map_filter']['node_radius']) - $this->mapOptions['map_filter']['rank_sep'] * GRAPHVIZ_RADIUS_CONVERSION_FACTOR);
            } else if (preg_match('/^edge.*$/', $line) != 0
                && empty($this->relations) === true
            ) {
                // Edge.
                // This is really not needed, because is already defined
                // in $this->relations. Only for debug purposes.
                $fields = explode(' ', $line);

                if (strpos($fields[1], 'transp_') !== false
                    || strpos($fields[2], 'transp_') !== false
                ) {
                    // Skip transparent nodes relationships.
                    continue;
                }

                $relations[] = [
                    'id_parent'             => $target_node['id_node'],
                    'parent_type'           => NODE_GENERIC,
                    'id_parent_source_data' => $mod_rel['module_b'],
                    'id_child'              => $node['id_node'],
                    'child_type'            => NODE_GENERIC,
                    'id_child_source_data'  => $mod_rel['module_a'],
                ];
            }
        }

        // Use current relationship definitions (if exists).
        if (empty($this->relations) === false) {
            $relations = $this->relations;
        }

        return [
            'nodes'     => $nodes,
            'relations' => $relations,
        ];

    }


    /**
     * Calculates X,Y positions foreach element defined in dotGraph.
     *
     * @return array Structure parsed.
     */
    public function calculateCoords()
    {
        global $config;

        switch (PHP_OS) {
            case 'WIN32':
            case 'WINNT':
            case 'Windows':
                $filename_dot = sys_get_temp_dir()."\\networkmap_".$filter;
            break;

            default:
                $filename_dot = sys_get_temp_dir().'/networkmap_'.$filter;
            break;
        }

        if ($this->mapOptions['simple']) {
            $filename_dot .= '_simple';
        }

        if ($this->mapOptions['nooverlap']) {
            $filename_dot .= '_nooverlap';
        }

        $filename_dot .= uniqid().'_'.$this->idMap.'.dot';

        file_put_contents($filename_dot, $this->dotGraph);

        $plain_file = 'plain'.uniqid().'.txt';
        switch (PHP_OS) {
            case 'WIN32':
            case 'WINNT':
            case 'Windows':
                $filename_plain = sys_get_temp_dir().'\\'.$plain_file;

                $cmd = io_safe_output(
                    $config['graphviz_bin_dir'].'\\'.$this->filter.'.exe -Tplain -o '.$filename_plain.' '.$filename_dot
                );
            break;

            default:
                $filename_plain = sys_get_temp_dir().'/'.$plain_file;

                $cmd = $this->filter.' -Tplain -o '.$filename_plain.' '.$filename_dot;
            break;
        }

        $retval = 0;
        $r = system($cmd, $retval);

        if ($retval != 0) {
            ui_print_error_message(
                __('Failed to generate dotmap, please select different layout schema')
            );
            return [];
        }

        unlink($filename_dot);

        if (function_exists($this->customParser)) {
            try {
                if (empty($this->customParserArgs)) {
                    $graph = call_user_func(
                        $this->customParser,
                        $filename_plain,
                        $this->dotGraph
                    );
                } else {
                    $graph = call_user_func(
                        $this->customParser,
                        $filename_plain,
                        $this->dotGraph,
                        $this->customParserArgs
                    );
                }
            } catch (Exception $e) {
                // If developer is using a custom method to parse graphviz
                // results, but want to handle using default parser
                // or custom based on data, it is possible to launch
                // exceptions to control internal flow.
                if ($this->fallbackDefaultParser === true) {
                    $graph = $this->parseGraphvizMapFile(
                        $filename_plain
                    );
                } else {
                    ui_print_error_message($e->getMessage());
                    $graph = [];
                }
            }
        } else {
            $graph = $this->parseGraphvizMapFile(
                $filename_plain
            );
        }

        unlink($filename_plain);

        /*
         * Graphviz section ends here.
         */

        return $graph;
    }


    /**
     * Creates an empty dot graph (with only base node)
     *
     * @return void
     */
    public function generateEmptyDotGraph()
    {
        // Create an empty map dot structure.
        $graph = $this->openDotFile();

        $this->nodes[0] = [
            'label'            => get_product_name(),
            'id_node'          => 0,
            'id_agente'        => 0,
            'id_agente_modulo' => 0,
            'node_type'        => NODE_PANDORA,
        ];

        $this->nodeMapping[0] = 0;

        $graph .= $this->createDotNode(
            $this->nodes[0]
        );

        $graph .= $this->closeDotFile();

        $this->dotGraph = $graph;
    }


    /**
     * Returns the most representative ID based on the tipe of node received.
     *
     * @param array $node Source data.
     *
     * @return integer Source id.
     */
    private function auxGetIdByType($node)
    {
        if (!is_array($node)) {
            return 0;
        }

        switch ($to_source['node_type']) {
            case NODE_MODULE:
            return $node['id_agente_modulo'];

            case NODE_AGENT:
            return $node['id_agente'];

            case NODE_GENERIC:
            return $node['id_node'];

            case NODE_PANDORA:
            default:
            return 0;
        }
    }


    /**
     * Generates a nodes - relationships array using graphviz dot
     * schema and stores nodes&relations into $this->graph.
     *
     * @return void
     */
    public function generateNetworkMap()
    {
        global $config;

        include_once 'include/functions_os.php';

        $map_filter = $this->mapOptions['map_filter'];

        /*
         * Let graphviz place the nodes.
         */

        if ($map_filter['empty_map']) {
            $this->generateEmptyDotGraph();
        } else if (!isset($this->dotGraph)) {
            $this->generateDotGraph();
        }

        /*
         * Calculate X,Y positions.
         */

        $graph = $this->calculateCoords();

        if (is_array($graph) === true) {
            $nodes = $graph['nodes'];
            $relations = $graph['relations'];
        } else {
            ui_print_error_message(
                __('Failed to retrieve graph data.')
            );
            return;
        }

        /*
         * Calculate references.
         */

        $index = 0;
        $node_center = [];

        $graph = [];
        $graph['nodes'] = [];

        // Prepare graph nodes.
        foreach ($nodes as $id => $coords) {
            $node_tmp['id_map'] = $this->idMap;
            $node_tmp['id'] = $id;

            $source = $this->getNodeData($id);

            $node_tmp['id_agent'] = $source['id_agente'];
            $node_tmp['id_module'] = $source['id_agente_modulo'];
            $node_tmp['type'] = $source['node_type'];
            $node_tmp['x'] = $coords['x'];
            $node_tmp['y'] = $coords['y'];

            $node_tmp['width'] = $this->mapOptions['map_filter']['node_radius'];
            $node_tmp['height'] = $this->mapOptions['map_filter']['node_radius'];

            if (isset($source['width'])) {
                $node_tmp['width'] = $source['width'];
            }

            if (isset($source['height'])) {
                $node_tmp['height'] = $source['height'];
            }

            switch ($node_tmp['type']) {
                case NODE_AGENT:
                    $node_tmp['source_data'] = $source['id_agente'];
                    $node_tmp['text'] = $source['alias'];
                    $node_tmp['image'] = ui_print_os_icon(
                        $source['id_os'],
                        false,
                        true,
                        true,
                        true,
                        true,
                        true
                    );
                break;

                case NODE_MODULE:
                    $node_tmp['source_data'] = $source['id_agente_modulo'];
                    $node_tmp['text'] = $source['nombre'];
                    $node_tmp['image'] = ui_print_moduletype_icon(
                        $this->getNodeData($id, 'id_tipo_modulo'),
                        true,
                        true,
                        false,
                        true
                    );
                break;

                case NODE_PANDORA:
                    $node_tmp['text'] = $source['label'];
                    $node_tmp['id_agent'] = $source['id_agente'];
                    $node_tmp['id_module'] = $source['id_agente_modulo'];
                    $node_tmp['source_data'] = 0;
                    $node_center['x'] = ($coords['x'] - MAP_X_CORRECTION);
                    $node_center['y'] = ($coords['y'] - MAP_Y_CORRECTION);
                break;

                case NODE_GENERIC:
                default:
                    $node_tmp['text'] = $source['label'];
                    $node_tmp['id_agent'] = $source['id_agente'];
                    $node_tmp['id_module'] = $source['id_agente_modulo'];
                    $node_tmp['source_data'] = $source['id_source'];
                break;
            }

            $style = [];
            $style['shape'] = $source['shape'];
            if (isset($style['shape']) === false) {
                $style['shape'] = 'circle';
            }

            $style['image'] = $node_tmp['image'];
            $style['width'] = $node_tmp['width'];
            $style['height'] = $node_tmp['height'];
            $style['label'] = $node_tmp['text'];

            $node_tmp['style'] = json_encode($style);

            $graph['nodes'][$index] = $node_tmp;
            $index++;
        }

        // Prepare graph edges and clean double references.
        $graph['relations'] = [];
        $parents = [];
        foreach ($relations as $rel) {
            $tmp = [
                'id_map'                => $this->idMap,
                'id_parent'             => $rel['id_parent'],
                'parent_type'           => $rel['parent_type'],
                'id_parent_source_data' => $rel['id_parent_source_data'],
                'id_child'              => $rel['id_child'],
                'child_type'            => $rel['child_type'],
                'id_child_source_data'  => $rel['id_child_source_data'],
                'id_parent_agent'       => $rel['id_parent_agent'],
                'id_child_agent'        => $rel['id_child_agent'],
                'link_color'            => $rel['link_color'],
                'text_start'            => $rel['text_start'],
                'text_end'              => $rel['text_end'],
            ];

            $found = 0;
            if (isset($tmp['id_parent_source_data'])) {
                // Avoid [child - parent] : [parent - child] relation duplicates.
                if (is_array($parents[$tmp['id_parent_source_data']])) {
                    foreach ($parents[$tmp['id_parent_source_data']] as $k) {
                        if ($k === $tmp['id_child_source_data']) {
                            $found = 1;
                            break;
                        }
                    }
                } else {
                    $parents[$tmp['id_parent_source_data']] = [];
                }
            }

            if ($found == 0) {
                $parents[$tmp['id_child_source_data']][] = $tmp['id_parent_source_data'];
                $graph['relations'][] = $tmp;
            }
        }

        // Prioritize relations between same nodes.
        $this->cleanGraphRelations();

        // Save data.
        if ($this->idMap > 0 && (isset($this->map['__simulated']) === false)) {
            if (enterprise_installed()) {
                $graph = enterprise_hook(
                    'save_generate_nodes',
                    [
                        $this->idMap,
                        $graph,
                    ]
                );
            }

            db_process_sql_update(
                'tmap',
                [
                    'width'    => $this->map['width'],
                    'height'   => $this->map['height'],
                    'center_x' => $this->map['center_x'],
                    'center_y' => $this->map['center_y'],
                ],
                ['id' => $this->idMap]
            );
        } else {
            $this->map['center_x'] = $node_center['x'];
            $this->map['center_y'] = $node_center['y'];

            if (!isset($this->map['center_x'])
                && !isset($this->map['center_y'])
            ) {
                $this->map['center_x'] = ($nodes[0]['x'] - MAP_X_CORRECTION);
                $this->map['center_y'] = ($nodes[0]['y'] - MAP_Y_CORRECTION);
            }
        }

        $this->graph = $graph;
    }


    /**
     * Transform node information into JS data.
     *
     * @return string HTML code with JS data.
     */
    public function loadMapData()
    {
        $networkmap = $this->map;

        $simulate = false;
        if (isset($networkmap['__simulated']) === false) {
            $networkmap['filter'] = json_decode(
                $networkmap['filter'],
                true
            );
            $networkmap['filter']['holding_area'] = [
                500,
                500,
            ];
            $holding_area_title = __('Holding Area');
        } else {
            $simulate = true;
            $holding_area_title = '';
            $networkmap['filter']['holding_area'] = [
                0,
                0,
            ];
        }

        // Prioritize relations between same nodes.
        $this->cleanGraphRelations();

        // Print some params to handle it in js.
        html_print_input_hidden('product_name', get_product_name());
        html_print_input_hidden('center_logo', ui_get_full_url(ui_get_logo_to_center_networkmap()));

        $output .= '<script type="text/javascript">
    ////////////////////////////////////////////////////////////////////
    // VARS FROM THE DB
    ////////////////////////////////////////////////////////////////////
    var url_background_grid = "'.ui_get_full_url('images/background_grid.png').'";
    ';
        $output .= 'var networkmap_id = "'.$this->idMap."\";\n";

        if (!empty($networkmap['filter'])) {
            if (empty($networkmap['filter']['x_offs'])) {
                $output .= "var x_offs =null;\n";
            } else {
                $output .= 'var x_offs ='.$networkmap['filter']['x_offs'].";\n";
            }

            if (empty($networkmap['filter']['y_offs'])) {
                $output .= "var y_offs =null;\n";
            } else {
                $output .= 'var y_offs ='.$networkmap['filter']['y_offs'].";\n";
            }

            if (empty($networkmap['filter']['z_dash'])) {
                $output .= "var z_dash =null;\n";
            } else {
                $output .= 'var z_dash = '.$networkmap['filter']['z_dash'].";\n";
            }
        } else {
            $output .= "var x_offs = null;\n";
            $output .= "var y_offs = null;\n";
            $output .= "var z_dash = null;\n";
        }

        $output .= 'var networkmap_refresh_time = 1000 * '.$networkmap['source_period'].";\n";
        $output .= 'var networkmap_center = [ '.$networkmap['center_x'].', '.$networkmap['center_y']."];\n";
        $output .= 'var networkmap_dimensions = [ '.$networkmap['width'].', '.$networkmap['height']."];\n";
        $output .= 'var enterprise_installed = '.((int) enterprise_installed()).";\n";
        $output .= 'var node_radius = '.$networkmap['filter']['node_radius'].";\n";
        $output .= 'var networkmap_holding_area_dimensions = '.json_encode($networkmap['filter']['holding_area']).";\n";
        $output .= "var networkmap = {'nodes': [], 'links':  []};\n";

        // Init.
        $count_item_holding_area = 0;
        $count = 0;

        // Translate nodes to JS Nodes.
        $nodes = $this->graph['nodes'];
        if (is_array($nodes) === false) {
            $nodes = [];
        }

        $nodes_js = $this->nodesToJS($nodes);
        $output .= 'networkmap.nodes = ('.json_encode($nodes_js).");\n";

        // Translate edges to js links.
        $relations = $this->graph['relations'];
        if (is_array($relations) === false) {
            $relations = [];
        }

        $links_js = $this->edgeToJS($relations);
        $output .= 'networkmap.links = ('.json_encode($links_js).");\n";

        $output .= '
        ////////////////////////////////////////////////////////////////////
        // INTERFACE STATUS COLORS
        ////////////////////////////////////////////////////////////////////
        ';

        $module_color_status = [];
        $module_color_status[] = [
            'status_code' => AGENT_MODULE_STATUS_NORMAL,
            'color'       => COL_NORMAL,
        ];
        $module_color_status[] = [
            'status_code' => AGENT_MODULE_STATUS_CRITICAL_BAD,
            'color'       => COL_CRITICAL,
        ];
        $module_color_status[] = [
            'status_code' => AGENT_MODULE_STATUS_WARNING,
            'color'       => COL_WARNING,
        ];
        $module_color_status[] = [
            'status_code' => AGENT_STATUS_ALERT_FIRED,
            'color'       => COL_ALERTFIRED,
        ];
        $module_color_status_unknown = COL_UNKNOWN;

        $output .= 'var module_color_status = '.json_encode($module_color_status).";\n";
        $output .= "var module_color_status_unknown = '".$module_color_status_unknown."';\n";

        $output .= '
        ////////////////////////////////////////////////////////////////////
        // Other vars
        ////////////////////////////////////////////////////////////////////
        ';

        $output .= "var translation_none = '".__('None')."';\n";
        $output .= "var dialog_node_edit_title = '".__('Edit node %s')."';\n";
        $output .= "var holding_area_title = '".$holding_area_title."';\n";
        $output .= "var edit_menu = '".__('Show details and options')."';\n";
        $output .= "var interface_link_add = '".__('Add a interface link')."';\n";
        $output .= "var set_parent_link = '".__('Set parent interface')."';\n";
        $output .= "var set_as_children_menu = '".__('Set as children')."';\n";
        $output .= "var set_parent_menu = '".__('Set parent')."';\n";
        $output .= "var abort_relationship_menu = '".__('Abort the action of set relationship')."';\n";
        $output .= "var delete_menu = '".__('Delete')."';\n";
        $output .= "var add_node_menu = '".__('Add node')."';\n";
        $output .= "var set_center_menu = '".__('Set center')."';\n";
        $output .= "var refresh_menu = '".__('Refresh')."';\n";
        $output .= "var refresh_holding_area_menu = '".__('Refresh Holding area')."';\n";
        $output .= "var ok_button = '".__('Proceed')."';\n";
        $output .= "var message_to_confirm = '".__('Resetting the map will delete all customizations you have done, including manual relationships between elements, new items, etc.')."';\n";
        $output .= "var warning_message = '".__('WARNING')."';\n";
        $output .= "var ok_button = '".__('Proceed')."';\n";
        $output .= "var cancel_button = '".__('Cancel')."';\n";
        $output .= "var restart_map_menu = '".__('Restart map')."';\n";
        $output .= "var abort_relationship_interface = '".__('Abort the interface relationship')."';\n";
        $output .= "var abort_relationship_menu = '".__('Abort the action of set relationship')."';\n";

        $output .= '</script>';

        return $output;
    }


    /**
     * Generates a simple interface to interact with nodes.
     *
     * @return string HTML code for simple interface.
     */
    public function loadSimpleInterface()
    {
        $output = '<div id="open_version_dialog" style="display: none;">';
        $output .= __(
            'In the Open version of %s can not be edited nodes or map',
            get_product_name()
        );
        $output .= '</div>';

        $output .= '<div id="dialog_node_edit" style="display: none;" title="';
        $output .= __('Edit node').'">';
        $output .= '<div style="text-align: left; width: 100%;">';

        $table = new StdClass();
        $table->id = 'node_details';
        $table->width = '100%';

        $table->data = [];
        $table->data[0][0] = '<strong>'.__('Agent').'</strong>';
        $table->data[0][1] = '';
        $table->data[1][0] = '<strong>'.__('Adresses').'</strong>';
        $table->data[1][1] = '';
        $table->data[2][0] = '<strong>'.__('OS type').'</strong>';
        $table->data[2][1] = '';
        $table->data[3][0] = '<strong>'.__('Group').'</strong>';
        $table->data[3][1] = '';

        $output .= ui_toggle(
            html_print_table($table, true),
            __('Node Details'),
            __('Node Details'),
            false,
            true
        );

        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }


    /**
     * Show an advanced interface to manage dialogs.
     *
     * @return string HTML code with dialogs.
     */
    public function loadAdvancedInterface()
    {
        $list_networkmaps = get_networkmaps($this->idMap);
        if (empty($list_networkmaps)) {
            $list_networkmaps = [];
        }

        $output .= '<div id="open_version_dialog" style="display: none;">';
        $output .= __(
            'In the Open version of %s can not be edited nodes or map',
            get_product_name()
        );
        $output .= '</div>';

        $output .= '<div id="dialog_node_edit" style="display: none;" title="';
        $output .= __('Edit node').'">';
        $output .= '<div style="text-align: left; width: 100%;">';

        $table = new StdClass();
        $table->id = 'node_details';
        $table->width = '100%';

        $table->data = [];
        $table->data[0][0] = '<strong>'.__('Agent').'</strong>';
        $table->data[0][1] = '';
        $table->data[1][0] = '<strong>'.__('Adresses').'</strong>';
        $table->data[1][1] = '';
        $table->data[2][0] = '<strong>'.__('OS type').'</strong>';
        $table->data[2][1] = '';
        $table->data[3][0] = '<strong>'.__('Group').'</strong>';
        $table->data[3][1] = '';

        $output .= ui_toggle(
            html_print_table($table, true),
            __('Node Details'),
            __('Node Details'),
            false,
            true
        );

        $table = new StdClass();
        $table->id = 'interface_information';
        $table->width = '100%';

        $table->head['interface_name'] = __('Name');
        $table->head['interface_status'] = __('Status');
        $table->head['interface_graph'] = __('Graph');
        $table->head['interface_ip'] = __('Ip');
        $table->head['interface_mac'] = __('MAC');
        $table->data = [];
        $table->rowstyle['template_row'] = 'display: none;';
        $table->data['template_row']['interface_name'] = '';
        $table->data['template_row']['interface_status'] = '';
        $table->data['template_row']['interface_graph'] = '';
        $table->data['template_row']['interface_ip'] = '';
        $table->data['template_row']['interface_mac'] = '';

        $output .= ui_toggle(
            html_print_table($table, true),
            __('Interface Information (SNMP)'),
            __('Interface Information (SNMP)'),
            true,
            true
        );

        $table = new StdClass();
        $table->id = 'node_options';
        $table->width = '100%';

        $table->data = [];
        $table->data[0][0] = __('Shape');
        $table->data[0][1] = html_print_select(
            [
                'circle'  => __('Circle'),
                'square'  => __('Square'),
                'rhombus' => __('Rhombus'),
            ],
            'shape',
            '',
            'javascript:',
            '',
            0,
            true
        ).'&nbsp;<span id="shape_icon_in_progress" style="display: none;">'.html_print_image('images/spinner.gif', true).'</span><span id="shape_icon_correct" style="display: none;">'.html_print_image('images/dot_green.png', true).'</span><span id="shape_icon_fail" style="display: none;">'.html_print_image('images/dot_red.png', true).'</span>';
        $table->data['node_name'][0] = __('Name');
        $table->data['node_name'][1] = html_print_input_text(
            'edit_name_node',
            '',
            __('name node'),
            '20',
            '50',
            true
        );
        $table->data['node_name'][2] = html_print_button(
            __('Update node'),
            '',
            false,
            '',
            'class="sub"',
            true
        );

        $table->data['fictional_node_name'][0] = __('Name');
        $table->data['fictional_node_name'][1] = html_print_input_text(
            'edit_name_fictional_node',
            '',
            __('name fictional node'),
            '20',
            '50',
            true
        );
        $table->data['fictional_node_networkmap_link'][0] = __('Networkmap to link');
        $table->data['fictional_node_networkmap_link'][1] = html_print_select(
            $list_networkmaps,
            'edit_networkmap_to_link',
            '',
            '',
            '',
            0,
            true
        );
        $table->data['fictional_node_update_button'][0] = '';
        $table->data['fictional_node_update_button'][1] = html_print_button(
            __('Update fictional node'),
            '',
            false,
            'add_fictional_node();',
            'class="sub"',
            true
        );

        $output .= ui_toggle(
            html_print_table($table, true),
            __('Node options'),
            __('Node options'),
            true,
            true
        );

        $table = new StdClass();
        $table->id = 'relations_table';
        $table->width = '100%';

        $table->head = [];
        $table->head['node_source'] = __('Node source');
        $table->head['interface_source'] = __('Interface source');
        $table->head['interface_target'] = __('Interface Target');

        $table->head['node_target'] = __('Node target');
        $table->head['edit'] = '<span title="'.__('Edit').'">'.__('E.').'</span>';

        $table->data = [];
        $table->rowstyle['template_row'] = 'display: none;';
        $table->data['template_row']['node_source'] = '';
        $table->data['template_row']['interface_source'] = html_print_select(
            [],
            'interface_source',
            '',
            '',
            __('None'),
            0,
            true
        );
        $table->data['template_row']['interface_target'] = html_print_select(
            [],
            'interface_target',
            '',
            '',
            __('None'),
            0,
            true
        );

        $table->data['template_row']['node_target'] = '';
        $table->data['template_row']['edit'] = '';

        $table->data['template_row']['edit'] .= '<span class="edit_icon_correct" style="display: none;">'.html_print_image('images/dot_green.png', true).'</span><span class="edit_icon_fail" style="display: none;">'.html_print_image('images/dot_red.png', true).'</span><span class="edit_icon_progress" style="display: none;">'.html_print_image('images/spinner.gif', true).'</span><span class="edit_icon"><a class="edit_icon_link" title="'.__('Update').'" href="#">'.html_print_image('images/config.png', true).'</a></span>';

        $table->data['template_row']['edit'] .= '<a class="delete_icon" href="#">'.html_print_image('images/delete.png', true).'</a>';

        $table->colspan['no_relations']['0'] = 5;
        $table->cellstyle['no_relations']['0'] = 'text-align: center;';
        $table->data['no_relations']['0'] = __('There are not relations');

        $table->colspan['loading']['0'] = 5;
        $table->cellstyle['loading']['0'] = 'text-align: center;';
        $table->data['loading']['0'] = html_print_image(
            'images/wait.gif',
            true
        );

        $output .= ui_toggle(
            html_print_table($table, true),
            __('Relations'),
            __('Relations'),
            true,
            true
        );

        $output .= '</div></div>';

        $output .= '<div id="dialog_interface_link" style="display: none;" title="Interface link">';
        $output .= '<div style="text-align: left; width: 100%;">';

        $table = new stdClass();
        $table->id = 'interface_link_table';
        $table->width = '100%';
        $table->head['node_source_interface'] = __('Node source');
        $table->head['interface_source_select'] = __('Interface source');
        $table->head['interface_target_select'] = __('Interface Target');
        $table->head['node_target_interface'] = __('Node target');

        $table->data = [];

        $table->data['interface_row']['node_source_interface'] = html_print_label(
            '',
            'node_source_interface',
            true
        );

        $table->data['interface_row']['interface_source_select'] = html_print_select(
            [],
            'interface_source_select',
            '',
            '',
            __('None'),
            0,
            true
        );

        $table->data['interface_row']['interface_target_select'] = html_print_select(
            [],
            'interface_target_select',
            '',
            '',
            __('None'),
            0,
            true
        );

        $table->data['interface_row']['node_target_interface'] = html_print_label(
            '',
            'node_target_interface',
            true
        );

        $output .= '<br>';

        $table->data['interface_row']['interface_link_button'] = html_print_button(
            __('Add interface link'),
            '',
            false,
            'add_interface_link_js();',
            'class="sub"',
            true
        );

        $output .= html_print_table($table, true);
        $output .= '</div></div>';

        $output .= '<div id="dialog_node_add" style="display: none;" title="';
        $output .= __('Add node').'">';
        $output .= '<div style="text-align: left; width: 100%;">';

        $table = new StdClass();
        $table->width = '100%';
        $table->data = [];

        $table->data[0][0] = __('Agent');
        $params = [];
        $params['return'] = true;
        $params['show_helptip'] = true;
        $params['input_name'] = 'agent_name';
        $params['input_id'] = 'agent_name';
        $params['print_hidden_input_idagent'] = true;
        $params['hidden_input_idagent_name'] = 'id_agent';
        $params['disabled_javascript_on_blur_function'] = true;
        $table->data[0][1] = ui_print_agent_autocomplete_input($params);
        $table->data[1][0] = '';
        $table->data[1][1] = html_print_button(
            __('Add agent node'),
            '',
            false,
            'add_agent_node();',
            'class="sub"',
            true
        ).html_print_image(
            'images/error_red.png',
            true,
            [
                'id'         => 'error_red',
                'style'      => 'vertical-align: bottom;display: none;',
                'class'      => 'forced_title',
                'alt'        => '',
                'data-title' => 'data-use_title_for_force_title:1',
            ],
            false
        );

        $add_agent_node_html = html_print_table($table, true);
        $output .= ui_toggle(
            $add_agent_node_html,
            __('Add agent node'),
            __('Add agent node'),
            false,
            true
        );

        $table = new StdClass();
        $table->width = '100%';
        $table->data = [];
        $table->data[0][0] = __('Group');
        $table->data[0][1] = html_print_select_groups(
            false,
            'IW',
            false,
            'group_for_show_agents',
            -1,
            'choose_group_for_show_agents()',
            __('None'),
            -1,
            true
        );
        $table->data[1][0] = __('Agents');
        $table->data[1][1] = html_print_select(
            [-1 => __('None')],
            'agents_filter_group',
            -1,
            '',
            '',
            0,
            true,
            true,
            true,
            '',
            false,
            'width: 170px;',
            false,
            5
        );
        $table->data[2][0] = '';
        $table->data[2][1] = html_print_button(
            __('Add agent node'),
            '',
            false,
            'add_agent_node_from_the_filter_group();',
            'class="sub"',
            true
        );

        $add_agent_node_html = html_print_table($table, true);
        $output .= ui_toggle(
            $add_agent_node_html,
            __('Add agent node (filter by group)'),
            __('Add agent node'),
            true,
            true
        );

        $table = new StdClass();
        $table->width = '100%';
        $table->data = [];
        $table->data[0][0] = __('Name');
        $table->data[0][1] = html_print_input_text(
            'name_fictional_node',
            '',
            __('name fictional node'),
            '20',
            '50',
            true
        );
        $table->data[1][0] = __('Networkmap to link');
        $table->data[1][1] = html_print_select(
            $list_networkmaps,
            'networkmap_to_link',
            '',
            '',
            '',
            0,
            true
        );
        $table->data[2][0] = '';
        $table->data[2][1] = html_print_button(
            __('Add fictional node'),
            '',
            false,
            'add_fictional_node();',
            'class="sub"',
            true
        );
        $add_agent_node_html = html_print_table($table, true);
        $output .= ui_toggle(
            $add_agent_node_html,
            __('Add fictional point'),
            __('Add agent node'),
            true,
            true
        );

        $output .= '</div></div>';

        return $output;
    }


    /**
     * Loads advanced map controller (JS).
     *
     * @return string HTML code for advanced controller.
     */
    public function loadController()
    {
        $output = '';

        if (enterprise_installed()
            && $this->useTooltipster
        ) {
            $output .= '<script type="text/javascript">
                var nodes = networkmap.nodes;
                var arrows = networkmap.links;
                var width = networkmap_dimensions[0];
                var height = networkmap_dimensions[1];
                var font_size = '.$this->mapOptions['font_size'].';
                var custom_params = '.json_encode($this->tooltipParams).';
                var controller = null;
                var homedir = "'.ui_get_full_url(false).'"
                $(function() {
                    controller = new SimpleMapController("#simple_map");
                    controller.init_map();
                });
            </script>';
        } else {
            // Generate JS for advanced controller.
            $output .= '

<script type="text/javascript">
    ////////////////////////////////////////////////////////////////////////
    // document ready
    ////////////////////////////////////////////////////////////////////////

    $(document).ready(function() {
        init_graph({
            graph: networkmap,
            networkmap_center: networkmap_center,
            networkmap_dimensions: networkmap_dimensions,
            enterprise_installed: enterprise_installed,
            node_radius: node_radius,
            holding_area_dimensions: networkmap_holding_area_dimensions,
            url_background_grid: url_background_grid,
            font_size: '.$this->mapOptions['font_size'].'
        });
        init_drag_and_drop();
        init_minimap();
        function_open_minimap();
        
        $(document.body).on("mouseleave",
            ".context-menu-list",
            function(e) {
                try {
                    $("#networkconsole_'.$this->idMap.'").contextMenu("hide");
                }
                catch(err) {
                }
            }
        );
    });
</script>';
        }

        if ($return === false) {
            echo $output;
        }

        return $output;

    }


    /**
     * Load networkmap HTML skel and JS requires.
     *
     * @return string HTML code for skel.
     */
    public function loadMapSkel()
    {
        global $config;

        if (enterprise_installed()
            && isset($this->useTooltipster)
            && $this->useTooltipster == true
        ) {
            $output .= '<script type="text/javascript" src="'.ui_get_full_url(
                'include/javascript/d3.3.5.14.js'
            ).'" charset="utf-8"></script>';
            $output .= '<script type="text/javascript" src="'.ui_get_full_url(
                'enterprise/include/javascript/SimpleMapController.js'
            ).'"></script>';
            $output .= '<script type="text/javascript" src="'.ui_get_full_url(
                'enterprise/include/javascript/tooltipster.bundle.min.js'
            ).'"></script>';
            $output .= '<script type="text/javascript" src="'.ui_get_full_url(
                'include/javascript/jquery.svg.js'
            ).'"></script>';
            $output .= '<script type="text/javascript" src="'.ui_get_full_url(
                'include/javascript/jquery.svgdom.js'
            ).'"></script>';
            $output .= '<link rel="stylesheet" type="text/css" href="'.ui_get_full_url(
                '/enterprise/include/styles/tooltipster.bundle.min.css'
            ).'" />'."\n";

            $output .= '<div id="simple_map" data-id="'.$this->idMap.'" ';
            $output .= 'style="border: 1px #ddd solid;';
            $output .= ' width:'.$this->mapOptions['width'];
            $output .= ' ;height:'.$this->mapOptions['height'].'">';
            $output .= '<svg id="svg'.$this->idMap.'" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" pointer-events="all" width="'.$this->mapOptions['width'].'" height="'.$this->mapOptions['height'].'px">';
            $output .= '</svg>';
            $output .= '</div>';
        } else {
            // Load default interface.
            ui_require_css_file('networkmap');
            ui_require_css_file('jquery.contextMenu', 'include/styles/js/');

            $output = '';
            $minimap_display = '';
            if ($this->mapOptions['pure']) {
                $minimap_display = 'none';
            }

            $networkmap = $this->map;
            if (is_array($networkmap['filter']) === false) {
                $networkmap['filter'] = json_decode($networkmap['filter'], true);
            }

            $networkmap['filter']['l2_network_interfaces'] = 1;

            $output .= '<script type="text/javascript" src="'.$config['homeurl'].'include/javascript/d3.3.5.14.js" charset="utf-8"></script>';
            if (isset($this->map['__simulated']) === false) {
                // Load context menu if manageable networkmap.
                $output .= '<script type="text/javascript" src="'.$config['homeurl'].'include/javascript/jquery.contextMenu.js"></script>';
            }

            $output .= '<script type="text/javascript" src="'.$config['homeurl'].'include/javascript/functions_pandora_networkmap.js"></script>';

            // Open networkconsole_id div.
            $output .= '<div id="networkconsole_'.$networkmap['id'].'"';
            $output .= ' style="width: '.$this->mapOptions['width'].'; height: '.$this->mapOptions['height'].';position: relative; overflow: hidden; background: #FAFAFA">';

            $output .= '<div style="display: '.$minimap_display.';">';
            $output .= '<canvas id="minimap_'.$networkmap['id'].'"';
            $output .= ' style="position: absolute; left: 0px; top: 0px; border: 1px solid #bbbbbb;">';
            $output .= '</canvas>';
            $output .= '<div id="arrow_minimap_'.$networkmap['id'].'"';
            $output .= ' style="position: absolute; left: 0px; top: 0px;">';
            $output .= '<a title="'.__('Open Minimap').'" href="javascript: toggle_minimap();">';
            $output .= '<img id="image_arrow_minimap_'.$networkmap['id'].'"';
            $output .= ' src="images/minimap_open_arrow.png" />';
            $output .= '</a><div></div></div>';

            $output .= '<div id="hide_labels_'.$networkmap['id'].'"';
            $output .= ' style="position: absolute; right: 10px; top: 10px;">';
            $output .= '<a title="'.__('Hide Labels').'" href="javascript: hide_labels();">';
            $output .= '<img id="image_hide_show_labels" src="images/icono_borrar.png" />';
            $output .= '</a></div>';

            $output .= '<div id="holding_spinner_'.$networkmap['id'].'" ';
            $output .= ' style="display: none; position: absolute; right: 50px; top: 20px;">';
            $output .= '<img id="image_hide_show_labels" src="images/spinner.gif" />';
            $output .= '</div>';

            // Close networkconsole_id div.
            $output .= "</div>\n";
        }

        return $output;
    }


    /**
     * Print all components required to visualizate a network map.
     *
     * @param boolean $return Return as string or not.
     *
     * @return string HTML code.
     */
    public function printMap($return=false)
    {
        global $config;

        // ACL.
        $networkmap_read = check_acl(
            $config['id_user'],
            $networkmap['id_group'],
            'MR'
        );
        $networkmap_write = check_acl(
            $config['id_user'],
            $networkmap['id_group'],
            'MW'
        );
        $networkmap_manage = check_acl(
            $config['id_user'],
            $networkmap['id_group'],
            'MM'
        );

        if (!$networkmap_read
            && !$networkmap_write
            && !$networkmap_manage
        ) {
            db_pandora_audit(
                'ACL Violation',
                'Trying to access networkmap'
            );
            include 'general/noaccess.php';
            return '';
        }

        $user_readonly = !$networkmap_write && !$networkmap_manage;

        if (isset($this->idMap)
            && isset($this->map['__simulated']) === false
        ) {
            $output .= $this->loadMapSkel();
            $output .= $this->loadMapData();
            $output .= $this->loadController();
            $output .= $this->loadAdvancedInterface();
        } else {
            // Simulated, no tmap entries.
            $output .= $this->loadMapSkel();
            $output .= $this->loadMapData();
            $output .= $this->loadController();
            $output .= $this->loadSimpleInterface();
        }

        $output .= '
<script type="text/javascript">
(function() {
  var hidden = "hidden";

  // Standards:
  if (hidden in document)
    document.addEventListener("visibilitychange", onchange);
  else if ((hidden = "mozHidden") in document)
    document.addEventListener("mozvisibilitychange", onchange);
  else if ((hidden = "webkitHidden") in document)
    document.addEventListener("webkitvisibilitychange", onchange);
  else if ((hidden = "msHidden") in document)
    document.addEventListener("msvisibilitychange", onchange);
  // IE 9 and lower:
  else if ("onfocusin" in document)
    document.onfocusin = document.onfocusout = onchange;
  // All others:
  else
    window.onpageshow = window.onpagehide
    = window.onfocus = window.onblur = onchange;

  function onchange (evt) {
    // Reset all action status variables. Window is not in focus or visible.
    flag_multiple_selection = false
    disabled_drag_zoom = false;
    flag_multiple_selection_running = false;
    selected = undefined;
  }

  // set the initial state (but only if browser supports the Page Visibility API)
  if( document[hidden] !== undefined )
    onchange({type: document[hidden] ? "blur" : "focus"});
})();
</script>

';
        if ($return === false) {
            echo $output;
        }

        return $output;
    }


}
