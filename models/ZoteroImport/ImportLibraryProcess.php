<?php
class ZoteroImport_ImportLibraryProcess extends ProcessAbstract
{
    protected $_libraryId;
    protected $_libraryType;
    protected $_collectionId;
    
    protected $_client;
    
    protected $_itemMetadata;
    protected $_elementTexts;
    protected $_fileMetadata;
    
    public function run($args)
    {
        ini_set('memory_limit', '500M');
        
        $this->_libraryId    = $args['libraryId'];
        $this->_libraryType  = $args['libraryType'];
        $this->_collectionId = $args['collectionId'];
        
        require_once 'ZoteroApiClient/Service/Zotero.php';
        $this->_client = new ZoteroApiClient_Service_Zotero($args['privateKey']);
        
        $this->_import();
    }
    
    protected function _import()
    {
        do {
            
            // Initialize the start parameter on the first library feed iteration.
            if (!isset($start)) {
                $start = 0;
            }
            
            // Get the library feed.
            $method = "{$this->_libraryType}ItemsTop";
            $feed = $this->_client->$method($this->_libraryId, array('start' => $start));
            
            // Set the start parameter for the next page iteration.
            if ($feed->link('next')) {
                $query = parse_url($feed->link('next'), PHP_URL_QUERY);
                parse_str($query, $query);
                $start = $query['start'];
            }
            
            // Iterate through this page's entries/items.
            foreach ($feed->entry as $item) {
                
                // Set default insert_item() arguments.
                $this->_itemMetadata = array('collection_id' => $this->_collectionId, 
                                             'public'        => true);
                $this->_elementTexts = array();
                $this->_fileMetadata = array('file_transfer_type'  => 'Url', 
                                             'file_ingest_options' => array('ignore_invalid_files' => true));
                
                // Map the title.
                $this->_elementTexts['Dublin Core']['Title'][] = array('text' => $item->title(), 'html' => false);
                
                // Map top-level attachment item.
                if ('attachment' == $item->itemType()) {
                    $this->_mapAttachment($item);
                }
                
                // Map the Zotero API field nodes to Omeka elements.
                if (is_array($item->content->div->table->tr)) {
                    foreach ($item->content->div->table->tr as $tr) {
                        $this->_mapFields($tr);
                    }
                } else {
                    $this->_mapFields($item->content->div->table->tr);
                }
                
                // Map Zotero tags to Omeka tags, comma-delimited.
                if ($item->numTags()) {
                    $method = "{$this->_libraryType}ItemTags";
                    $tags = $this->_client->$method($this->_libraryId, $item->itemID());
                    $tagArray = array();
                    foreach ($tags->entry as $tag) {
                        // Remove commas from Zotero tags, or Omeka will assume 
                        // they are separate tags.
                        $tagArray[] = str_replace(',', ' ', $tag->title);
                    }
                    $this->_itemMetadata['tags'] = join(',', $tagArray);
                }
                
                // Map Zotero children (notes & attachments).
                if ($item->numChildren()) {
                    $method = "{$this->_libraryType}ItemChildren";
                    $children = $this->_client->$method($this->_libraryId, $item->itemID());
                    foreach ($children->entry as $child) {
                        switch ($child->itemType()) {
                            case 'note':
                                $noteXpath = '//default:tr[@class="note"]/default:td/default:p';
                                $note = $this->_contentXpath($child->content, $noteXpath, true);
                                $this->_elementTexts['Zotero']['Note'][] = array('text' => (string) $note, 'html' => false);
                                break;
                            case 'attachment':
                                $this->_mapAttachment($child);
                                break;
                            default:
                                break;
                        }
                    }
                }
                
                // Insert the item.
                $item = insert_item($this->_itemMetadata, $this->_elementTexts, $this->_fileMetadata);
                release_object($item);
            }
            
        } while ($feed->link('self') != $feed->link('last'));
    }
    
    protected function _mapFields(Zend_Feed_Element $tr)
    {
        // Only map those field nodes that exist in the mapping 
        // array.
        if ($elementName = $this->_getElementName($tr['class'])) {
            
            if ($elementName['dc']) {
                // Map the field nodes to the correlating Dublin Core element 
                // set field elements.
                $this->_elementTexts['Dublin Core'][$elementName['dc']][] = array('text' => $tr->td(), 'html' => false);
            }
            
            if ($elementName['z']) {
                // The creator node is formatted differently than other field 
                // nodes. Account for this by mapping a creator node to the 
                // correlating Zotero element set creator element.
                if ('creator' == $tr['class'] && in_array($tr->th(), $elementName['z'])) {
                    $this->_elementTexts['Zotero'][$tr->th()][] = array('text' => $tr->td(), 'html' => false);
                
                // Map the field nodes to the correlating Zotero element set 
                // field elements.
                } else {
                    $this->_elementTexts['Zotero'][$elementName['z']][] = array('text' => $tr->td(), 'html' => false);
                }
            }
        }
   }
   
   protected function _mapAttachment(Zend_Feed_Element $element)
   {
        $urlXpath = '//default:tr[@class="url"]/default:td';
        $url = $this->_contentXpath($element->content, $urlXpath, true);
        if ($url) {
            $this->_elementTexts['Dublin Core']['Identifier'][] = array('text' => (string) $url, 'html' => false);
        }
        $method = "{$this->_libraryType}ItemFile";
        $location = $this->_client->$method($this->_libraryId, $element->itemID());
        if ($location) {
            $this->_fileMetadata['files'][] = array('source' => $location, 'name' => $element->title());
        }
   }
    
    protected function _getElementName($fieldName)
    {
        foreach (ZoteroImportPlugin::$zoteroFields as $zoteroFieldName => $map) {
            if ($fieldName == $zoteroFieldName) {
                return $map;
            }
        }
        return false;
    }
    
    protected function _contentXpath(Zend_Feed_Element $content, $xpath, $fetchOne = false)
    {
        $xml = simplexml_load_string($content->div->saveXml());
        $xml->registerXPathNamespace('default', 'http://www.w3.org/1999/xhtml');
        
        // Experimental: automatically namespace each node in the xpath.
        //$xpath = preg_replace('#(/)([a-z])#i', '$1default:$2', $xpath);
        
        $result = $xml->xpath($xpath);
        if ($fetchOne) {
            return $result[0];
        }
        return $result;
    }
}