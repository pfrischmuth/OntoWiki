<?php

/**
 * OntoWiki resource list model class.
 *
 * Represents a list of resources (of a certain rdf:type) and their properties.
 * 
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @category OntoWiki
 * @package Model
 * @copyright Copyright (c) 2008, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 * @author Norman Heino <norman.heino@gmail.com>
 * @author Jonas Brekle <jonas.brekle@gmail.com>
 */
class OntoWiki_Model_Instances extends OntoWiki_Model
{
    protected $mode;
    const modeType = "type"; 
    const modeSearch = "search";
    const modeGiven = "given";
    const modeAll = "all";

    //search
    protected $searchText;

    //all
    protected $allTriple;

    /**
     *  rdf:type for the resources of interest
     * @var string
     */
    protected $_type = null;
    /**
     *  array of subclasses of $_type
     * @var array
     */
    protected $_subClasses = array();
    /**
     *  rdf:type for the resources of interest
     * @var Erfurt_Sparql_Query2_IriRef
     */
    protected $_memberPredicate = null;
    
 
    protected $_allProperties;
    protected $_allPropertiesUptodate  = false;
    
    /**
     * Properties whose values are to be fetched for each resource.
     * @var array
     */
    protected $_shownProperties = array();
    protected $_shownPropertiesConverted;
    protected $_shownPropertiesConvertedUptodate  = false;
    protected $_ignoredShownProperties = array(
        //EF_RDF_TYPE
    );
    
    /**
     * values of the set properties for all resources
     */
    protected $_values;
    protected $_valuesUptodate = false;
    
    /**
     * all resources
     */
    protected $_resources;
    protected $_resourcesUptodate = false;
    protected $_resourcesConverted;
    protected $_resourcesConvertedUptodate = false;
    /**
     * 
     * @var Erfurt_Sparql_Query2_Var
     */
    protected $_resourceVar = null;

    /**
     * @var array
     */
    protected $_filter = array();

    /**
     * Result array - what comes back when evaluating the query.
     * @var array
     */
    protected $_results = null;
    protected $_resultsUptodate = false;
    
    /**
     * @var Erfurt_Sparql_Query2
     */
    protected $_resourceQuery = null;
    /**
     * @var Erfurt_Sparql_Query2
     */
    protected $_valueQuery = null;
    protected $_valueQueryResourceFilter = null;
    
    /**
     * Constructor
     */
public function __construct (Erfurt_Store $store, $graph, $options = array())
    {
        parent::__construct($store, $graph);
        
        $this->_resourceQuery   =  new Erfurt_Sparql_Query2();
        $this->_resourceVar = new Erfurt_Sparql_Query2_Var("resourceUri");

        $this->allTriple = new Erfurt_Sparql_Query2_Triple($this->_resourceVar, new Erfurt_Sparql_Query2_Var("p"), new Erfurt_Sparql_Query2_Var("o"));
        $this->_resourceQuery->addElement($this->allTriple);
        
        //show resource uri
        $this->_resourceQuery->addProjectionVar($this->_resourceVar);
        $this->_resourceQuery
            ->setLimit(10) //per default query only for 10 resources
            ->setDistinct(true)
            ->getOrder()
                ->add($this->_resourceVar);

        // when resourceVar is the object - prevent literals
        $this->_resourceQuery->addFilter(
            new Erfurt_Sparql_Query2_ConditionalAndExpression(
                array(
                    new Erfurt_Sparql_Query2_isUri($this->_resourceVar),
                    
                    new Erfurt_Sparql_Query2_UnaryExpressionNot(
                        new Erfurt_Sparql_Query2_isBlank($this->_resourceVar)
                    )
                )
            )
        );

        //echo 'resource query: <pre>';
        //echo htmlentities($this->_resourceQuery);
        //echo '</pre>'; exit;

        //build value query
        $this->_valueQuery = new Erfurt_Sparql_Query2();

        $this->_valueQuery
            ->addProjectionVar($this->_resourceVar)
            ->setDistinct(true);

        //always query for type (not optional)
        $typeVar = new Erfurt_Sparql_Query2_Var('__TYPE');
        $optional = new Erfurt_Sparql_Query2_OptionalGraphPattern();
        $optional->addTriple(
            $this->_resourceVar,
            new Erfurt_Sparql_Query2_IriRef(EF_RDF_TYPE),
            $typeVar
        );
        $this->_valueQuery->addElement($optional);
        $this->_valueQuery->addProjectionVar($typeVar);

        //$this->updateValueQuery();
    }

    /**
     * dont keep the references to the query objects in $this->resourceQuery (the must be cloned too)
     */
    public function __clone(){
        foreach ($this as $key => $val) {
            if (is_object($val)||(is_array($val))) {
                $this->{$key} = unserialize(serialize($val));
                //$this->$key= clone($this->$key); 
            }
        }
    }

   /**
    * add ?resourceUri ?p ?o to the query
    * TODO: support objects as resources? optionally?
    */
    public function addAllTriple($withObjects = false){
        $this->_resourceQuery->addElement(
            $this->allTriple
        );
    }
    
    /**
     * Adds a property to the properties fetched for every resource.
     *
     * @param $propertyUri The URI of the property
     * @param $propertyName Name to be used for the variable
     * @return OntoWiki_Model_ResourceList
     */
    public function addShownProperty ($propertyUri, $propertyName = null, $inverse = false, $datatype = null)
    {
        if (in_array($propertyUri, $this->_ignoredShownProperties)) {
            return $this; //no action
        }

        if (!$propertyName) {
            $propertyName = preg_replace('/^.*[#\/]/', '', $propertyUri);
            $propertyName = str_replace('-', '', $propertyName);
        }

        $used = false;
        foreach ($this->_shownProperties as $shownProp) {
            if ($shownProp['name'] == $propertyName) {
                $used = true;
            }
        }
        //solve duplicate name problem by adding counter
        if ($used) {
            $counter = 2;
            while ($used) {
                $name = $propertyName . $counter++;
                $used = false;
                foreach ($this->_shownProperties as $shownProp) {
                    if ($shownProp['name'] == $name){
                        $used = true;
                    }
                }
            }

            $propertyName = $name;
        }
        
        $ret = Erfurt_Sparql_Query2_Abstraction_ClassNode::addShownPropertyHelper(
            $this->_valueQuery, 
            $this->_resourceVar, 
            $propertyUri, 
            $propertyName, 
            $inverse
        );
        
        $this->_shownProperties[$propertyUri.'-'.$inverse] = array(
            'uri' => $propertyUri,
            'name' => $propertyName, 
            'inverse' => $inverse, 
            'datatype' => $datatype, 
            'varName' => $ret['var']->getName(),
            'var' => $ret['var'],
            'optionalpart' => $ret['optional'],
            'filter' => $ret['filter']
        );
        $this->_valuesUptodate = false; // getValues will not use the cache next time
        $this->_resultsUptodate = false;

        //echo 'add shown property: <pre>';
        //echo htmlentities($this->_valueQuery);
        //echo '</pre>';
        
        return $this;
    }

    /**
     *
     * @param string> $key the uri
     */
    public function removeShownProperty($key){
        if(isset($this->_shownProperties[$key])){
            $prop =  $this->_shownProperties[$key];
            $this->_valueQuery->removeProjectionVar($prop['var']);
            $prop['optionalpart']->remove();
            //$prop['filter']->remove();
            unset($this->_shownProperties[$key]);
        }
    }

    /**
     * queries for values (unconverted)
     * @return array
     */
    public function getResults ()
    {
        if (!$this->_resultsUptodate) {
            $this->_results = $this->_model->sparqlQuery(
                $this->_valueQuery, 
                array('result_format' => 'extended')
            );
            $this->_resultsUptodate = true;
        }
        //echo '<pre>'; print_r($this->_results); echo '</pre>';
        return $this->_results;
    }
    

    /**
     * add a filter from the filter box - these filters match some predefined schemes
     * (like "equals", "contains")
     * @param string $id
     * @param string $property
     * @param boolean $isInverse
     * @param string $propertyLabel
     * @param string $filter
     * @param string $value1
     * @param string $value2
     * @param string $valuetype
     * @param string $literaltype
     * @return string id
     */
    public function addFilter ($property, $isInverse, $propertyLabel, $filter, $value1 = null, $value2 = null, $valuetype = 'literal', $literaltype = null, $hidden = false, $id = null, $negate = false)
    {
        if($id == null){
            $id = "box" . count($this->_filter);
        }
        $prop = new Erfurt_Sparql_Query2_IriRef($property);
        //echo "<pre>"; print_r($parts);echo "</pre>"; exit;
        if(!empty($value1)){
            switch($valuetype) {
                case 'uri':
                    $value1_obj = new Erfurt_Sparql_Query2_IriRef($value1);

                    if (!empty($value2)){
                        $value2_obj = new Erfurt_Sparql_Query2_IriRef($value2);
                    }
                break;
                case 'literal':
                    if (!empty($literaltype)) {
                            //with language tags
                            $value1_obj = new Erfurt_Sparql_Query2_RDFLiteral(
                                $value1,
                                $literaltype
                            );
                            if (!empty($value2)){
                                $value2_obj = new Erfurt_Sparql_Query2_RDFLiteral(
                                    $value2,
                                    $literaltype);
                            }
                        } else {
                            //no language tags
                            if(!is_numeric($value1)){
                                $value1_obj = new Erfurt_Sparql_Query2_RDFLiteral($value1);
                            } else {
                                $value1_obj = new Erfurt_Sparql_Query2_NumericLiteral($value1);
                            }
                            if (!empty($value2)){
                                if(!is_numeric($value2)){
                                    $value1_obj = new Erfurt_Sparql_Query2_RDFLiteral($value2);
                                } else {
                                    $value1_obj = new Erfurt_Sparql_Query2_NumericLiteral($value2);
                                }
                            }
                        }
                break;
                case 'typed-literal':
                    if (in_array($literaltype, Erfurt_Sparql_Query2_RDFLiteral::$knownShortcuts)) {
                        //is something like "bool" or "int"
                        $value1_obj = new Erfurt_Sparql_Query2_RDFLiteral($value1, $literaltype);
                        if (!empty($value2)){
                            $value2_obj =
                            new Erfurt_Sparql_Query2_RDFLiteral($value2, $literaltype);
                        }
                    } else {
                        // is a uri
                        $value1_obj = new Erfurt_Sparql_Query2_RDFLiteral($value1, new Erfurt_Sparql_Query2_IriRef($literaltype));
                        if (!empty($value2)){
                            $value2_obj = new Erfurt_Sparql_Query2_RDFLiteral(
                                $value2,
                                new Erfurt_Sparql_Query2_IriRef($literaltype)
                            );
                        }
                    }
                break;
                default:
                    throw new RuntimeException(
                        'called Ontowiki_Model_Instances::addFilter with '.
                        'unknown param-value: valuetype = "'.$valuetype.'"'
                    );
                break;
            }
        }
        
        switch($filter) {
            case 'contains':
                $var = new Erfurt_Sparql_Query2_Var($propertyLabel);
                if (!$isInverse) {
                    $triple = $this->_resourceQuery->addTriple(
                        $this->_resourceVar, 
                        $prop, 
                        $var
                    );
                } else {
                    $triple = $this->_resourceQuery->addTriple(
                        $var, 
                        $prop, 
                        $this->_resourceVar
                    );
                }

                $filterObj = $this->_resourceQuery->addFilter(
                    !$negate ?
                    new Erfurt_Sparql_Query2_Regex(
                        new Erfurt_Sparql_Query2_Str($var), 
                        $value1_obj
                    )
                    :
                    new Erfurt_Sparql_Query2_UnaryExpressionNot(
                        new Erfurt_Sparql_Query2_Regex(
                            new Erfurt_Sparql_Query2_Str($var),
                            $value1_obj
                        )
                    )
                );
            break;
            case 'equals':
                if ($valuetype=="literal") {
                    $valueVar = new Erfurt_Sparql_Query2_Var($propertyLabel);
                    if (!$isInverse) {
                        $triple = $this->_resourceQuery->addTriple(
                            $this->_resourceVar, 
                            $prop, 
                            $valueVar
                        );
                    } else {
                        throw new RuntimeException(
                            'literal as value for an inverse property '.
                            'is a literal subject which is not allowed');
                    }
                    
                    $filterObj = $this->_resourceQuery->addFilter(
                        new Erfurt_Sparql_Query2_Regex(
                            $valueVar, 
                            new Erfurt_Sparql_Query2_RDFLiteral('^'.$value1.'$')
                        )
                    );
                } else {
                    if (!$isInverse) {
                        $triple = $this->_resourceQuery->addTriple(
                            $this->_resourceVar, 
                            $prop, 
                            $value1_obj
                        );
                    } else {
                        $triple = $this->_resourceQuery->addTriple(
                            $value1_obj,
                            $prop, 
                            $this->_resourceVar
                        );
                    }
                }
            break;
            case 'larger':
                $var = new Erfurt_Sparql_Query2_Var($propertyLabel);
                if (!$isInverse) {
                    $triple = $this->_resourceQuery->addTriple(
                        $this->_resourceVar,
                        $prop,
                        $var
                    );
                } else {
                    $triple = $this->_resourceQuery->addTriple(
                        $var,
                        $prop,
                        $this->_resourceVar
                    );
                }

                $filterObj = $this->_resourceQuery->addFilter(
                    new Erfurt_Sparql_Query2_Larger($var, $value1_obj)
                );
            break;
            case 'smaller':
                $var = new Erfurt_Sparql_Query2_Var($propertyLabel);
                if (!$isInverse) {
                    $triple = $this->_resourceQuery->addTriple(
                        $this->_resourceVar,
                        $prop,
                        $var
                    );
                } else {
                    $triple = $this->_resourceQuery->addTriple(
                        $var,
                        $prop,
                        $this->_resourceVar
                    );
                }

                $filterObj = $this->_resourceQuery->addFilter(
                    new Erfurt_Sparql_Query2_Smaller($var, $value1_obj)
                );
            break;
            case 'between':
                $var = new Erfurt_Sparql_Query2_Var($propertyLabel);
                if (!$isInverse) {
                    $triple = $this->_resourceQuery->addTriple(
                        $this->_resourceVar,
                        $prop,
                        $var
                    );
                } else {
                    $triple = $this->_resourceQuery->addTriple(
                        $var,
                        $prop,
                        $this->_resourceVar
                    );
                }

                $filterObj = $this->_resourceQuery->addFilter(
                    new Erfurt_Sparql_Query2_ConditionalAndExpression(
                        array(
                            new Erfurt_Sparql_Query2_Larger($var, $value1_obj),
                            new Erfurt_Sparql_Query2_Smaller($var, $value2_obj)
                        )
                    )
                );
            break;
            case 'bound':
                $var = new Erfurt_Sparql_Query2_Var($propertyLabel);
                
                if (!$isInverse) {
                    $triple = new Erfurt_Sparql_Query2_Triple(
                        $this->_resourceVar,
                        $prop,
                        $var
                    );
                } else {
                    $triple = new Erfurt_Sparql_Query2_Triple(
                        $var,
                        $prop,
                        $this->_resourceVar
                    );
                }
                if($negate){
                    $optional = new Erfurt_Sparql_Query2_OptionalGraphPattern();
                    $optional->addElement($triple);
                    $this->_resourceQuery->addElement($optional);
                    $triple = $optional; // to save this obj (see underneath 20 lines)
                } else {
                    $this->_resourceQuery->addElement($triple);
                }

                if($negate){
                    $filterObj = $this->_resourceQuery->addFilter(
                        new Erfurt_Sparql_Query2_UnaryExpressionNot(
                            new Erfurt_Sparql_Query2_bound($var)
                        )
                    );
                }
            break;
            default:
                throw new RuntimeException(
                    'called Ontowiki_Model_Instances::addFilter with '.
                    'unknown param-value: filtertype='.$filter
                );
            break;
            
        }

        //these filters bring there own triple
        $this->allTriple->remove();

        //save
        $this->_filter[$id] = array(
             'id'               => $id,
             'mode'             => 'box',
             'property'         => $property,
             'isInverse'        => $isInverse,
             'propertyLabel'    => $propertyLabel,
             'filter'           => $filter,
             'value1'           => $value1,
             'value2'           => $value2,
             'valuetype'        => $valuetype,
             'literaltype'      => $literaltype,
             'hidden'           => $hidden,
             'negate'           => $negate,
             'objects'          => array($triple, isset($filterObj) ? $filterObj : null)
        );

        //print_r($this->_filter[$id]);

        //echo 'new resource query<pre>'; echo htmlentities($this->_resourceQuery); echo '</pre>';
        $this->invalidate();
        $this->updateValueQuery();
        return $id;
    }

    /**
     * remove a filter by id
     * @param string $id
     * @return OntoWiki_Model_Instances $this
     */
    public function removeFilter($id){
        if (isset($this->_filter[$id])){
            foreach($this->_filter[$id]['objects'] as $obj){
                if($obj instanceof Erfurt_Sparql_Query2_ElementHelper){
                    $obj->remove();
                    //echo "removed: ".$obj;
                }
            }

            unset($this->_filter[$id]);
            
            //when all deleted
            if (count($this->_resourceQuery->getWhere()->getElements()) == 1){ //this last element is the "isUri and !isBlank"-filter
                $this->addAllTriple();
            }

            //echo '<pre>'; echo htmlentities($this->_resourceQuery); echo '</pre>';
            $this->invalidate();
            return $this;
        }
    }

    /**
     * get the array that holds the filters
     * @return array
     */
    public function getFilter(){
        return $this->_filter;
    }

    /**
     *
     * @param string $type the uri of the class
     * @param string $id
     * @param array $options
     * @return int id
     */
    public function addTypeFilter($type, $id = null, $option = array()){
        if($id == null){
            $id = "type" . count($this->_filter);
        }

        //shortcut navigation - only a rdfs class given
        $options['mode'] = 'instances';
        $options['type'] = $type;
        $options['memberPredicate'] = EF_RDF_TYPE;
        $options['withChilds'] = true;

        $options['hierarchyUp'] = EF_RDFS_SUBCLASSOF;
        $options['hierarchyIsInverse'] = true;
        //$options['hierarchyDown'] = null;
        $options['direction'] = 1; // down the tree

        $member_predicate = $options['memberPredicate'];
        if (is_string($member_predicate)){
            $member_predicate = new Erfurt_Sparql_Query2_IriRef($member_predicate);
        }

        if (!($member_predicate instanceof Erfurt_Sparql_Query2_Verb)) {
            throw new RuntimeException(
                'Option "member_predicate" passed to Ontowiki_Model_Instances '.
                'must be an instance of Erfurt_Sparql_Query2_IriRef '.
                'or string instance of '.typeHelper($member_predicate).' given');
        }

        $type = new Erfurt_Sparql_Query2_IriRef($options['type']);
        if ($options['withChilds']) {
            $this->_subClasses =
                array_keys(
                    // get subclasses:
                    $this->_store->getTransitiveClosure(
                        $this->_graph,
                        $options['hierarchyUp'],
                        array($type->getIri()),
                        $options['hierarchyIsInverse']
                    )
                );
        } else if(isset($options['subtypes'])){ //dont query, take the given. maybe the new navigation can use this
            $this->_subClasses = $options['subtypes'];
        }

        if (count($this->_subClasses)>1) {
            // there are subclasses. "1" because the class itself is somehow included in the subclasses...
            $typeVar = new Erfurt_Sparql_Query2_Var($type);
            $triple = $this->_resourceQuery->addTriple(
                $this->_resourceVar,
                $member_predicate,
                $typeVar);

            $or = new Erfurt_Sparql_Query2_ConditionalOrExpression();
            foreach ($this->_subClasses as $subclass) {
                $or->addElement(
                    new Erfurt_Sparql_Query2_sameTerm(
                        $typeVar,
                        new Erfurt_Sparql_Query2_IriRef($subclass)
                    )
                );
            }

            $filterObj = $this->_resourceQuery->addFilter($or);
        } else {
            // no subclasses
            $triple = $this->_resourceQuery->addTriple(
                $this->_resourceVar,
                $member_predicate,
                new Erfurt_Sparql_Query2_IriRef($this->_subClasses[0])
            );
        }

        //save
        $this->_filter[$id] = array(
             'id'               => $id,
             'mode'             => 'rdfsclass',
             'rdfsclass'             => $options['type'],
             'withChilds'       => $options['withChilds'],
             'objects'           => array($triple, isset($filterObj) ? $filterObj : null)
        );

        //print_r($this->_filter[$id]);

        //echo 'new resource query<pre>'; echo htmlentities($this->_resourceQuery); echo '</pre>';
        $this->invalidate();
        return $id;
    }

    /**
     *
     * @param string $str
     * @param string $id optional
     * @return string the id used
     */
    public function addSearchFilter($str, $id = null){
        if($id == null){
            $id = "search" . count($this->_filter);
        }
        $pattern = $this->_store->getSearchPattern(
            $str,
            $this->_graph
        );

        $vars = array();

        foreach($pattern as $element){
            if(method_exists($element, 'getVars')){
                $vars = array_merge($vars, $element->getVars());
            } //else echo "missing getVars() in: ".get_class($element);
        }
        $count = count($this->_filter);
        foreach($vars as $var){
            if($var->getName() == 'o' || $var->getName() == 'p') {
                $var->setName($var->getName().$count);
            }
        }
        $this->_resourceQuery->addElements($pattern);
        //var_dump($pattern);
        //save
        $this->_filter[$id] = array(
             'id'               => $id,
             'mode'             => 'search',
             'searchText'       => $str,
             'objects'           => $pattern
        );

        //print_r($this->_filter[$id]);

        $this->invalidate();
        $this->searchText = $str;

        //echo 'new resource query<pre>'; echo htmlentities($this->_resourceQuery); echo '</pre>';
        //exit;
        return $id;
    }

    /**
     * add arbitrary triples to the query to filter (used by the navigation)
     * @param array $triples
     * @param string $id
     * @return string the id used
     */
    public function addTripleFilter($triples, $id = null){
        if($id == null){
            $id = "triple" . count($this->_filter);
        }
        $this->_resourceQuery->addElements($triples);

        //save
        $this->_filter[$id] = array(
             'id'               => $id,
             'mode'             => 'triples',
             'objects'           => $triples
        );
        
        //these filters bring there own triple
        $this->allTriple->remove();

        $this->invalidate();
        return $id;
    }

    /**
     * get the query used to get the values (shownproperties)
     * @return Erfurt_Sparql_Query2
     */
    public function getQuery ()
    {
        return $this->_valueQuery;
    }

    /**
     * get the query used for getting the resources. incl. filter
     * @return Erfurt_Sparql_Query2
     */
    public function getResourceQuery ()
    {
        return $this->_resourceQuery;
    }

    /**
     * build a link that recreates the current state on a different system
     * @return string
     */
    public function getPermalink(){
        $url = 'init/1/';
        if($this->getOffset() != 0 && $this->getLimit() != 0){
            $url .= 'p/'. (($this->getOffset() / $this->getLimit()) + 1 ).'/';
        }
        if($this->getLimit() != 10){
            $url .= 'limit/'.$this->getLimit().'/';
        }
        $conf = array();
        
        if(is_array($this->_shownProperties) && count($this->_shownProperties) > 0){
            $conf['shownProperties'] = array();
            
            foreach($this->_shownProperties as $shownProperty){
                $conf['shownProperties'][] = array(
                    'uri' => $shownProperty['uri'],
                    'label' => $shownProperty['name'],
                    'inverse' => $shownProperty['inverse'], 
                    'action' => 'add'
                );
            }
        }
        if(is_array($this->_filter) && count($this->_filter) > 0){
            $conf['filter'] = array();
            
            foreach($this->_filter as $filter){
                switch($filter['mode']){
                    case 'box':
                        $arr = array(
                            'action' => 'add',
                            'mode' => 'box'
                        );
                        $arr = array_merge($arr, $filter);
                        $conf['filter'][] = $arr;
                    break;
                    case "search":
                        $conf['filter'][] = array(
                            'action' => 'add',
                            'mode' => 'search',
                            'searchText' => $filter['searchText']
                        );
                    break;
                    case "rdfsclass":
                        $conf['filter'][] = array(
                            'action' => 'add',
                            'mode' => 'rdfsclass',
                            'rdfsclass' => $filter['rdfsclass']
                        );
                    break;
                    case "triples":
                        //problem: php objects can not be json encoded ...
                        /*
                        $conf['filter'][] = array(
                            'action' => 'add',
                            'mode' => 'triples',
                            'triples' => $filter['triples'] 
                        );
                        */
                    break;
                }
            }
            $url .= '?m=' . urlencode((string) $this->_model);
            if(!empty($conf)){
                $url .= '&instancesconfig=' . urlencode(json_encode($conf));
            }
        }

        return $url;
    }
    
    /**
     * @return Erfurt_sparql_Query2_Var the var that is used as subject in the query
     */
    public function getResourceVar ()
    {
        return $this->_resourceVar;
    }
    
    /**
     * Returns the property values for all resources at once.
     *
     * @return array
     */
    public function getValues ()
    {
        if ($this->_valuesUptodate) {
            return $this->_values;
        } 
        if (empty($this->_resources)) {
            return array();
        }
        //echo htmlentities($this->_valueQuery);
        $this->getResults();

        $result = $this->_results['bindings'];
        //echo 'unconverted values: <pre>';  print_r($result);  echo '</pre>';
        $titleHelper = new OntoWiki_Model_TitleHelper($this->_model);

        foreach ($result as $row) {
            foreach ($this->_shownProperties as $propertyUri => $property) {
                if (
                    isset($row[$property['varName']])
                    && $row[$property['varName']]['type'] == 'uri'
                    && substr($row[$property['varName']]['value'], 0, 2) != "_:"
                ) {
                    $titleHelper->addResource($row[$property['varName']]['value']);
                }
            }
            if ( isset($row['__TYPE'])
                 &&
                 $row['__TYPE']['type'] == 'uri' //sould both be true
                ) {
                    $titleHelper->addResource($row['__TYPE']['value']);
            }
            $titleHelper->addResource($row['resourceUri']['value']);
        }

        $valueResults = array();
        foreach ($result as $row) {
            $resourceUri = $row['resourceUri']['value'];

            if (!array_key_exists($resourceUri, $valueResults)) {
                $valueResults[$resourceUri] = array();
            }

            $url = new OntoWiki_Url(array('route' => 'properties'), array('r'));

            $value = null;
            $link  = null;
            $uri   = null;

            foreach ($row as $varName => $data) {
                if (!isset($valueResults[$resourceUri][$varName])) {
                    $valueResults[$resourceUri][$varName] = array();
                }

                if ($data['type'] == 'uri') {
                    if(substr($data['value'], 0, 2) == "_:"){
                        continue; // skip blanknode values here due to backend problems with filters
                    }

                    // object type is uri --> handle object property
                    $objectUri = $data['value'];
                    $url->setParam('r', $objectUri, true);
                    $link = (string)$url;

                    // set up event
                    $event = new Erfurt_Event('onDisplayObjectPropertyValue');

                    //find uri
                    foreach ($this->_shownProperties as $property) {
                        if ($varName == $property['varName']) {
                            $event->property = $property['uri'];
                        }
                    }
                    $event->value    = $objectUri;

                    // trigger
                    $value = $event->trigger();

                    // set default if event has not been handled
                    if (!$event->handled()) {
                        $value = $titleHelper->getTitle($objectUri, $this->_lang);
                    }
                } else {
                    // object is a literal
                    $object = $data['value'];

                    // set up event
                    $event = new Erfurt_Event('onDisplayLiteralPropertyValue');
                    $event->property = $propertyUri;
                    $event->value    = $object;
                    $event->setDefault($object);

                    // trigger
                    $value = $event->trigger();
                }
                
                //check for dulplicate values
                if(isset($valueResults[$resourceUri][$varName])){
                    foreach($valueResults[$resourceUri][$varName] as $old){
                        if($old['origvalue'] == $data['value'] && $old['type'] == $data['type']){
                            continue 2; // dont add this value
                        }
                    }
                }

                //add value
                $valueResults[$resourceUri][$varName][] = array(
                  'value' => $value,
                  'origvalue' => $data['value'],
                  'type'  => $data['type'],
                  'url'   => $link,
                  'uri'   => $data['value'] //TODO: rename (can be literal) -> use origvalue + type to output uri
                );

                
                $value = null;
                $link  = null;
                $uri   = null;
            }

        }

        foreach($this->getShownResources() as $resource){
            if(!isset($valueResults[$resource])){
                //there are no statements about this resource
                $valueResults[$resource] = array();
            }
        }

        //echo 'converted values: <pre>';  print_r($valueResults);  echo '</pre>';
        $this->_values = $valueResults;
        $this->_valuesUptodate = true;
        
        return $valueResults;
    }
    
    public function getAllProperties ($inverse = false)
    {
        //echo 'call to getAllProperties(inverse = '.($inverse?"true":"false").")";
        $query = clone $this->_resourceQuery;
        $query
            ->removeAllProjectionVars()
            ->setDistinct(true)
            ->setLimit(0)
            ->setOffset(0);

        $predVar = new Erfurt_Sparql_Query2_Var('p');
        if(!$inverse){
            $query->addTriple(
                $this->_resourceVar,
                $predVar,
                new Erfurt_Sparql_Query2_Var('o')
            );
        } else {
            $query->addTriple(
                new Erfurt_Sparql_Query2_Var('o'),
                $predVar,
                $this->_resourceVar
            );
        }
        
        $query
            ->addProjectionVar($predVar)
            ->getOrder()
                ->clear()
                ->add($predVar);

        
        $results = $this->_model->sparqlQuery(
            $query,
            array('result_format' => 'extended')
        );
        

        $properties = array();
        foreach ($results['bindings'] as $row) {
            $properties[] = array('uri' => $row['p']['value']);
        }

        return $this->convertProperties($properties);
    }


    /**
     * get the bound values for a predicate
     * @param Erfurt_Sparql_Query2_IriRef|string $property
     * @param boolean $distinct
     * @param boolean $inverse
     * @return array
     */
    public function getPossibleValues ($property, $distinct = true, $inverse = false)
    {
        if (is_string($property)) {
            $property = new Erfurt_Sparql_Query2_IriRef($property);
        }
        if (!($property instanceof Erfurt_Sparql_Query2_IriRef)) {
            throw new RuntimeException(
                'Argument 1 passed to OntoWiki_Model_Instances::getObjects '.
                'must be instance of string or Erfurt_Sparql_Query2_IriRef, '.
                typeHelper($property).' given'
            );
        }

        $query = clone $this->_resourceQuery;
        $query
            ->removeAllProjectionVars()
            ->setDistinct($distinct)
            ->setLimit(0)
            ->setOffset(0);
        
        $valueVar = new Erfurt_Sparql_Query2_Var('obj');
        if($inverse){
            $query->addTriple($valueVar, $property, $this->_resourceVar);
        } else {
            $query->addTriple($this->_resourceVar, $property, $valueVar);
        }
        $query->addFilter(
            new Erfurt_Sparql_Query2_ConditionalAndExpression(
                array(
                    //new Erfurt_Sparql_Query2_isUri($valueVar),
                    // when resourceVar is the object - prevent literals
                    new Erfurt_Sparql_Query2_UnaryExpressionNot(
                        new Erfurt_Sparql_Query2_isBlank($valueVar)
                    )
                )
            )
        );
        $query->addProjectionVar($valueVar);
        
        $results = $this->_model->sparqlQuery(
            $query,
            array('result_format' => 'extended')
        );

        $values = array();
        foreach ($results['bindings'] as $row) {
            $values[] = $row['obj'];
        }

        return $values;
    }

    /**
     * get link-url, curi, title for an array of properties
     * @param array $properties
     * @return array
     */
    protected function convertProperties ($properties)
    {
        $titleHelper = new OntoWiki_Model_TitleHelper($this->_model);
        
        $uris = array();
        foreach ($properties as $property) {
            $uris[] = $property['uri'];
        }
        
        $titleHelper->addResources($uris);
        
        $url = new OntoWiki_Url(array('route' => 'properties'), array('r'));
    
       $propertyResults = array();
       $i = 0;
        foreach ($properties as $property) {
            if (in_array($property['uri'], $this->_ignoredShownProperties)) {
                continue;
            }

            // set URL
            $url->setParam('r', $property['uri'], true);

            $property['url'] = (string) $url;

            $property['curi'] = OntoWiki_Utils::contractNamespace($property['uri']);

            $property['title'] = $titleHelper->getTitle($property['uri'], $this->_lang);

            $propertyResults[] = $property;
        }
        
        return $propertyResults;
    }
    
    /**
     * Returns information about the properties fetched (title etc.)
     *
     * @return array
     */
    public function getShownProperties ()
    {
        if ($this->_shownPropertiesConvertedUptodate) {
            return $this->_shownPropertiesConverted;
        }
            
        $this->getResults();
            
        $converted = $this->convertProperties($this->_shownProperties);
        $this->_shownPropertiesConverted = $converted;
        $this->_shownPropertiesConvertedUptodate = true;
        return $converted;
    }

    /**
     * array of shownproperties (each is a array:
     * array (
     *      'uri'
            'name'
            'inverse'
            'datatype'
            'varName'
            'var' // var objectused as object
            'optionalpart' //the hole optional {?resourceUri <prop> ?var} pattern object
            'filter' // the FILTER(!isBlank(?var)) object
     * )
     * @return array
     */
    public function getShownPropertiesPlain () {
        return $this->_shownProperties;
    }
    
    /**
     * get titles and build link-uris for a array of resource uris
     * @param array $resources an array of resource uris
     * @return array
     */
    public function convertResources ($resources)
    {
        $url = new OntoWiki_Url(array('route' => 'properties'), array('r'));
        
        // add titles
        $titleHelper = new OntoWiki_Model_TitleHelper($this->_model);
        $titleHelper->addResources($resources);

        $resourceResults = array();

        foreach ($resources as $uri) {
            if (!array_key_exists($uri, $resourceResults)) {
                $resourceResults[$uri] = array();
            }

            // URL
            $url->setParam('r', $uri, true);
            $resourceResults[$uri]['url'] = (string) $url;

            // title
            $resourceResults[$uri]['title'] =
                $titleHelper->getTitle($uri, $this->_lang);
        }
        return $resourceResults;
    }
    
    public function getShownResources ()
    {
        if (!$this->_resourcesUptodate) {
            $result = $this->_model->sparqlQuery(
                $this->_resourceQuery,
                array('result_format' => 'extended')
            );

            $this->_resources = array();
            foreach ($result['bindings'] as $row) {
                $uri = $row['resourceUri']['value'];
                $this->_resources[] = $uri;
            }
            $this->_resourcesUptodate = true;
        } 
        
        return $this->_resources;
    }
    
    /**
     * Returns information about the resources queried
     *
     * @return array
     */
    public function getResources ()
    {
        if ($this->_resourcesConvertedUptodate) {
           return $this->_resourcesConverted;
        }

        $this->_resourcesConverted = $this->convertResources($this->getShownResources());
        $this->_resourcesConvertedUptodate = true;
        return $this->_resourcesConverted;
    }
    
    /**
     * Returns whether the model has data.
     *
     * @return boolean
     */
    public function hasData ()
    {
        $this->getShownResources();
        return !empty($this->_resources);
    }
    
    /**
     * Sets the maximum number of resources to fetch for one page.
     *
     * @param int $limit
     * @return OntoWiki_Model_Instances
     */
    public function setLimit ($limit)
    {
        if($this->_resourceQuery->getLimit() == $limit){
            return $this;
        }

        if($limit < 0){
            $limit *= -1;
        }

        $this->_resourceQuery->setLimit($limit);
        $this->invalidate();
        return $this;
    }
    
    /**
     * Sets the number of resources to be skipped for the current page.
     *
     * @param int $offset
     * @return OntoWiki_Model_Instances
     */
    public function setOffset ($offset)
    {
        if($this->_resourceQuery->getOffset() == $offset){
            return $this;
        }

        if($offset < 0){
            $offset *= -1;
        }

        $this->_resourceQuery->setOffset($offset);
        $this->invalidate();
        return $this;
    }
    
    /** Gets the maximum number of resources to fetch for one page.
     *
     * @return int
     */
    public function getLimit ()
    {
        return $this->_resourceQuery->getLimit();
    }
    
    /**
     * Gets the number of resources to be skipped for the current page.
     *
     * @return int
     */
    public function getOffset ()
    {
        return $this->_resourceQuery->getOffset();
    }

    /*
     * set all "uptodate" flags to false
     * @return OntoWiki_Model_Instances
     */
    public function invalidate ()
    {
        $this->_resourcesConvertedUptodate = false;
        $this->_resourcesUptodate = false;
        $this->_shownPropertiesConvertedUptodate = false;
        $this->_resultsUptodate = false;
        $this->_valuesUptodate = false;
        $this->_allPropertiesUptodate  = false;
        return $this;
    }

    /**
     * if the selected resources changed (due to filters or limit or offset)
     * we have to change the value query as well (because the resources are mentioned as subjects)
     * @return OntoWiki_Model_Instances $this
     */
    public function updateValueQuery ()
    {
        if($this->_resourcesUptodate){
            return $this;
        }
        
        $resources = $this->getShownResources();
        //echo 'resources: <pre>'; print_r($resources); echo '</pre>';

        foreach ($resources as $key => $resource) {
            $resources[$key] =
                new Erfurt_Sparql_Query2_SameTerm(
                    $this->_resourceVar,
                    new Erfurt_Sparql_Query2_IriRef($resource)
                );
        }

        if ($this->_valueQueryResourceFilter == null) {
            $this->_valueQueryResourceFilter = new Erfurt_Sparql_Query2_Filter(new Erfurt_Sparql_Query2_BooleanLiteral(false));
            $this->_valueQuery->addElement($this->_valueQueryResourceFilter);
        }

        $this->_valueQueryResourceFilter->setConstraint(
            empty($resources) ? 
                new Erfurt_Sparql_Query2_BooleanLiteral(false) :
                new Erfurt_Sparql_Query2_ConditionalOrExpression($resources)
        );

        //echo 'updated value query: <pre>';
        //echo htmlentities($this->_valueQuery);
        //echo '</pre>';
        
        return $this;
    }

    /**
     * get the last text that has been searched for
     * @return string
     */
    public function getSearchText(){
        return $this->searchText;
    }
}

