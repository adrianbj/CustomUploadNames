<?php namespace ProcessWire;

/**
 * ProcessWire Custom Upload Names
 * by Adrian Jones
 *
 * Automatically rename file/image uploads according to a configurable format
 *
 * Copyright (C) 2026 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class ProcessCustomUploadNames extends WireData implements Module, ConfigurableModule {

    /**
     * Guard against recursive renaming when save() is called inside the Pages::saved hook
     */
    private $renaming = false;

    /**
     * getModuleInfo is a module required by all modules to tell ProcessWire about them
     *
     * @return array
     *
     */
    public static function getModuleInfo() {
        return array(
            'title' => __('Custom Upload Names'),
            'version' => '2.0.0',
            'author' => 'Adrian Jones',
            'summary' => __('Automatically rename file/image uploads according to a configurable format'),
            'href' => 'http://modules.processwire.com/modules/process-custom-upload-names/',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'edit'
        );
    }

   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
            return array(
                "getVideoThumbs" => 1,
                "tempDisabled" => "",
                "enabledFields" => "",
                "enabledPages" => "",
                "enabledTemplates" => "",
                "filenameFormat" => "",
                "fileExtensions" => "",
                "filenameLength" => "",
                "renameOnSave" => "",
                "ruleData" => ""
            );
    }

    /**
     * Populate the default config data
     *
     */
    public function __construct() {
       foreach(self::getDefaultData() as $key => $value) {
               $this->$key = $value;
       }
    }

    /**
     * Initialize the module
     */
    public function init() {
    }


    public function ready() {

        // Check for AJAX request and process as appropriate
        if($this->wire('config')->ajax) {
            if($this->wire('input')->get->addRule) {
                $this->addRule((int) $this->wire('input')->get->addRule);
            }
        }

        // only load js file if we're on the module config settings for this module, rather than any other module
        if($this->className() == $this->wire('input')->get->name) $this->addHookAfter("ProcessModule::executeEdit", $this, "addScript");


        if($this->ruleData!='') {
            // page in the admin
            $processPage = $this->wire('page');
            $className = (string) $processPage->process;
            if($className == '') return;
            $fullClassName = __NAMESPACE__ . "\\{$className}";
            if(!class_exists($fullClassName)) return;
            $implements = class_implements($fullClassName);
            if($processPage->process && $implements && in_array(__NAMESPACE__ . '\\WirePageEditor', $implements)) {
                $this->addHookBefore('InputfieldFile::fileAdded', $this, 'customRenameUploads', array('priority'=>10));
            }
            // front-end API
            else {
                $this->addHookAfter('Pagefile::install', $this, 'customRenameUploads');
            }
            $this->addHookBefore('Pages::saved', $this, 'customRenameUploads');
        }
    }


    protected function customRenameUploads(HookEvent $event) {

        if($this->renaming) return;

        $pageid = null;

        // admin
        $process = $this->wire('process');
        if($process instanceof WirePageEditor) {
            if(!isset($process->getPage()->template) || $process->getPage()->template == 'language') return;
            $pagefile = $event->argumentsByName("pagefile");
            $field = $event->object;
            $method = 'admin';
            if($pagefile) {
                $action = 'upload';
                $pageid = $pagefile->pagefiles->getPage()->id;
                $field = $this->wire('fields')->get($field->name);
                if(!$field || !$field->id) return;
                $fieldid = $field->id;
            }
            else {
                $action = 'save';
                $pageid = $event->arguments(0)->id;
            }
        }
        // API
        else {
            $method = 'api';
            if($event->object->field) {
                $action = 'upload';
                $pagefile = $event->object;
                if($pagefile) $pageid = $pagefile->pagefiles->getPage()->id;
                $field = $event->object->field;
                if(!$field->type instanceof FieldtypeFile) return;
                $fieldid = $field->id;
            }
            else {
                $action = 'save';
                $pageid = $event->arguments(0)->id;
            }
        }

        if(!$pageid) return; // avoids interactions with other modules

        $uploadPage = $this->wire('pages')->get($pageid);
        if(!$uploadPage->id) return;

        if(method_exists($uploadPage, 'getForPage')) {
            $editedPage = $uploadPage->getForPage();
        }
        elseif($this->wire('input')->get->context == 'PageTable' && $process instanceof WirePageEditor) {
            $editedPage = $this->wire('pages')->get("FieldtypePageTable=".$process->getPage().", include=all");
        }
        else {
            $editedPage = $uploadPage;
        }


        $files = array();
        if($action == 'upload') {
            // if page belongs to a repeater or pagetable field
            if(method_exists($uploadPage, 'getForPage') || $this->wire('input')->get->context == 'PageTable') {
                $files[] = $pagefile->filename . '|' . $pageid . '|' . $fieldid; // add filename with respective repeater/pagetable pageid and fieldid to array
            }
            else {
                $files[] = $pagefile->filename . '|' . $fieldid; // add filename with respective fieldid to array
            }
        }
        elseif($action == 'save' && is_object($editedPage->fields)) {
            $files = $this->getAllFilenames($editedPage, true);
        }

        if(empty($files)) return;

        // ruleData is a json string that we need to turn into an object
        $rules = json_decode($this->ruleData);
        if(!is_array($rules)) return;

        foreach($files as $file) {
            // if it was a repeater field updating on save, then need to get pageid of repeater field
            $repeaterPage = null;
            $elements = explode('|', $file);
            $filename = $elements[0];
            $repeaterid = isset($elements[2]) ? (int) $elements[1] : null;
            $fieldid = (int) (isset($elements[2]) ? $elements[2] : $elements[1]);

            if($repeaterid) {
                $repeaterPage = $this->wire('pages')->get($repeaterid);
                $repeaterPage->of(false);
            }

            // quick fix to prevent this module from renaming video thumbs from GetVideoThumbs module
            if($this->wire('modules')->isInstalled('ProcessGetVideoThumbs') && $this->data['getVideoThumbs'] == 1) {
                if(strpos($filename,'youtube') !== false || strpos($filename,'vimeo') !== false) continue;
            }

            $filePage = $repeaterPage ? $repeaterPage : $editedPage;
            $filePage->of(false);

            // iterate through each of the rename rules
            foreach ($rules as $rule) {

                $parentEnabled = false;
                if(isset($rule->enabledPages)) {
                    foreach($editedPage->parents as $parent) {
                        if(in_array($parent->id, $rule->enabledPages) || in_array(1, $rule->enabledPages)) {
                            $parentEnabled = true;
                            break;
                        }
                    }
                }

                // all the conditions to not rename
                if($rule->tempDisabled == '1') continue;
                if(is_array($rule->enabledFields) && count($rule->enabledFields) && !in_array($fieldid, $rule->enabledFields)) continue; // if fields set and this is not a selected field
                if(is_array($rule->enabledTemplates) && count($rule->enabledTemplates) && !in_array($editedPage->template->id, $rule->enabledTemplates)) continue;
                if(isset($rule->enabledPages) && !empty($rule->enabledPages) && $rule->enabledPages[0] != '' && !in_array($editedPage->id, $rule->enabledPages) && !$parentEnabled) continue;
                if($rule->fileExtensions != '' && !in_array(pathinfo($filename, PATHINFO_EXTENSION), explode(",", trim(str_replace(', ',',',$rule->fileExtensions))))) continue; // if fileExtensions is set and the uploaded file does not match

                // for these next rules, break rather than continue because these are not specificity rules. No match is a positive result and so we don't want to test the next rule.
                if($rule->filenameFormat == '') break; // don't attempt to rename if the filename format field is empty
                // check if filename has -n extension and if so we do a rename on save to remove the -n if we can
                preg_match('/(.*)-\d+$/', pathinfo($filename, PATHINFO_FILENAME), $matches);
                if($rule->renameOnSave != '1' && $action == 'save' && strpos(pathinfo($filename, PATHINFO_FILENAME),'-upload-tmp') === false && count($matches) === 0) break; // -upload-tmp set when the filename format is not available yet because field is empty.

                // build the new filename
                $oldFilename = $filePage->filesManager()->path() . basename($filename);
                $newFilename = $this->createNewFilename($oldFilename, $rule->filenameFormat, $rule->filenameLength, $editedPage, $fieldid, $repeaterPage);

                if($oldFilename == $newFilename) continue;

                // rename the file
                if($action == 'upload') {

                    if(file_exists($oldFilename)) {
                        $pagefile->rename(pathinfo($newFilename, PATHINFO_BASENAME));
                        // set image as temp because the rename method removes this
                        // image will have temp status removed once page is saved
                        if(!$field->overwrite && $method == 'admin') $pagefile->isTemp(true);
                    }
                }
                elseif($action == 'save') { // saving from admin or api

                    // check if only the dedup number changed — if so, skip to prevent churn on each save
                    $filenameSansNum = $this->stripDedupNumber($oldFilename, $rule->filenameFormat);
                    $newFilenameSansNum = $this->stripDedupNumber($newFilename, $rule->filenameFormat);
                    if($filenameSansNum == $newFilenameSansNum && file_exists($oldFilename)) continue;

                    $field = $this->wire('fields')->get($fieldid);
                    $file = $filePage->$field->get("name=$filename");
                    $filePage->$field->trackChange("filename");
                    if($field->type instanceof FieldtypeImage) {
                        if(!is_null($file)) {
                            $oldName = pathinfo($oldFilename, PATHINFO_FILENAME);
                            $newName = pathinfo($newFilename, PATHINFO_FILENAME);
                            $newDir = pathinfo($newFilename, PATHINFO_DIRNAME);
                            $oldExt = pathinfo($oldFilename, PATHINFO_EXTENSION);
                            foreach($file->getVariations() as $imageVariation) {
                                $varName = pathinfo($imageVariation->filename, PATHINFO_FILENAME);
                                $targetPath = $newDir . '/' . $newName . str_replace($oldName, '', $varName) . '.' . $oldExt;
                                if(file_exists($imageVariation->filename) && !file_exists($targetPath)) {
                                    rename($imageVariation->filename, $targetPath);
                                }
                            }
                        }
                        $this->replaceRteLinks($newFilename, $oldFilename);
                    }

                    if(!is_null($file)) {
                        $file->rename(pathinfo($newFilename, PATHINFO_BASENAME));
                        $this->renaming = true;
                        $filePage->save($field->name);
                        $this->renaming = false;
                    }
                }
                break; // need to break out of $rules foreach once there has been a match and the file has been renamed.
            }

        }

    }

    private function replaceRteLinks($newFilename, $oldFilename) {
        $textareaFields = $this->wire('fields')->find("type=FieldtypeTextarea|FieldtypeTextareaLanguage");
        if(!$textareaFields->count()) return;
        $fieldsStr = $textareaFields->implode('|', 'name');

        // Build the URL prefix for the old and new filenames
        // Only the filename changes — the directory path stays the same
        $parts = explode("/", pathinfo($newFilename, PATHINFO_DIRNAME));
        $pid = end($parts);
        $filesUrl = $this->wire('pages')->get($pid)->filesManager()->url();
        $oldUrlBase = $filesUrl . pathinfo($oldFilename, PATHINFO_FILENAME);
        $newUrlBase = $filesUrl . pathinfo($newFilename, PATHINFO_FILENAME);

        foreach($this->wire('pages')->find("$fieldsStr%=$oldUrlBase, include=all") as $p) {
            foreach($textareaFields as $taf) {
                if($p->$taf != '' && strpos($p->$taf, $oldUrlBase) !== false) {
                    // Simple string replacement handles base images and variations alike
                    // e.g. /site/assets/files/1234/old-name.jpg → /site/assets/files/1234/new-name.jpg
                    //      /site/assets/files/1234/old-name.500x300.jpg → /site/assets/files/1234/new-name.500x300.jpg
                    $html = str_replace($oldUrlBase, $newUrlBase, $p->$taf);
                    if($html !== $p->$taf) {
                        $p->of(false);
                        $p->$taf = $html;
                        $p->save($taf);
                    }
                }
            }
        }
    }

    /**
     * Resolve variable references in a format string
     *
     * Supports:
     *   $var->prop           (simple property access)
     *   {$var->prop->sub}    (chained property access)
     *   $var                 (direct variable)
     *   {$var}               (direct variable in braces)
     *
     * @param string $format The format string containing variable references
     * @param array $vars Associative array of variable name => object mappings
     * @param bool &$blankField Set to true if any referenced variable resolves to empty
     * @return string The resolved string
     */
    private function resolveFormat($format, $vars, &$blankField) {
        // Match {$var->prop->method()->...} (braced, any depth) and $var->prop (unbraced, any depth)
        $result = preg_replace_callback(
            '/\{\$([a-zA-Z_]\w*)((?:->[a-zA-Z_]\w*(?:\(\))?)*)\}|\$([a-zA-Z_]\w*)((?:->[a-zA-Z_]\w*(?:\(\))?)*)/',
            function($matches) use ($vars, &$blankField) {
                // Braced match uses groups 1,2; unbraced uses groups 3,4
                $varName = !empty($matches[1]) ? $matches[1] : (isset($matches[3]) ? $matches[3] : '');
                $propChain = !empty($matches[2]) ? $matches[2] : (isset($matches[4]) ? $matches[4] : '');

                if(!isset($vars[$varName])) return $matches[0];

                $value = $vars[$varName];

                // Walk the property chain (e.g. ->parent->title or ->filesizeStr())
                if($propChain !== '') {
                    // Split on -> while preserving method () markers
                    preg_match_all('/[a-zA-Z_]\w*(?:\(\))?/', $propChain, $propMatches);
                    foreach($propMatches[0] as $prop) {
                        if(!is_object($value)) {
                            $value = '';
                            break;
                        }
                        if(substr($prop, -2) === '()') {
                            $method = substr($prop, 0, -2);
                            if(method_exists($value, $method) || method_exists($value, '___' . $method)) {
                                $value = $value->$method();
                            }
                            else {
                                $value = '';
                                break;
                            }
                        }
                        else {
                            $value = $value->$prop;
                        }
                    }
                }

                $resolved = (string) $value;
                if($resolved === '') $blankField = true;
                return $resolved;
            },
            $format
        );
        return $result;
    }

    /**
     * Generate the new filename based on the user set config options
     *
     */
    private function createNewFilename($filename, $newname, $filenameLength, $editedPage, $fieldid, $repeaterPage = null) {

        $path_parts = pathinfo($filename);

        // filename format can support $page, $template, $field, and $file variables in the format as defined in the module config settings
        // if repeater page, need to use parent page for determining name
        $page = $editedPage;
        $page->of(false); // needed here for when using via API and formatted value set to automatic
        $field = $this->wire('fields')->get($fieldid);
        $template = $page->template;

        $filePage = $repeaterPage !== null ? $repeaterPage : $editedPage;

        $file = $filePage->$field->get("name={$path_parts['basename']}");


        $page->of(true); // turned this on for allowing datetime field outputformatting to come through in filenames, rather than unixtimestamps

        // check if the field is a language alternate field and if so, set the user language to this language
        $originalLanguage = null;
        if($this->wire('languages')) {
            $originalLanguage = $this->wire('user')->language;
            $arr = explode('_', $field->name);
            $fileLanguageName = end($arr);
            $language = $this->wire('languages')->get($fileLanguageName);
            if($language->id) $this->wire('user')->language = $language;
        }


        if(strpos($newname,'randstring') !== false) { // process the length from random string request
            if(preg_match("/\[(.*?)\]/", $newname, $length)) {
                $newname = str_replace('randstring['.$length[1].']', $this->generateRandomString($length[1]), $newname);
            }
        }
        elseif(strpos($newname,'[') !== false) { // expecting a date format string for formatting the current datetime
            if(preg_match("/\[(.*?)\]/", $newname, $dateformat_array)) {
                $newname = str_replace($dateformat_array[0], date($dateformat_array[1]), $newname);
            }
        }

        // resolve variable references in the filename format
        $vars = array(
            'page' => $page,
            'template' => $template,
            'field' => $field,
            'file' => $file,
            'filePage' => $filePage,
        );
        $blankField = false;
        $resolvedName = $this->resolveFormat($newname, $vars, $blankField);
        $page->of(false);
        if($originalLanguage) $this->wire('user')->language = $originalLanguage;

        if($blankField || $resolvedName == '') {
            if(strpos($path_parts['filename'],'-upload-tmp') === false) {
                $newname = $path_parts['filename'] . '-upload-tmp'; // this allows the filename to be renamed on page save if the field for the format wasn't available at upload
            }
            else {
                $newname = $path_parts['filename'];
            }
        }
        else {
            $newname = $resolvedName;
        }

        // remove any encoded entities
        $newname = $this->wire('sanitizer')->unentities($newname);

        // truncate final new name before checking to see if "-n" needs to be appended
        if($filenameLength != '') $newname = $this->truncate($newname, $filenameLength);

        $n = 0;
        // the file being renamed should not count as a collision with itself
        $currentBasename = $path_parts['basename'];
        // if a number mask (### etc) is supplied in the filename format
        if(strpos($newname,'#') !== false) {
            do {
                $n++;
                $custom_n = str_pad($n, substr_count($newname, '#'), '0', STR_PAD_LEFT);
                $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . '-'. $custom_n . '.' . $path_parts['extension'];
                $candidateBasename = pathinfo($finalFilename, PATHINFO_BASENAME);
                $candidateBasename = pathinfo($finalFilename, PATHINFO_BASENAME);
            } while($candidateBasename !== $currentBasename && (in_array($candidateBasename, $this->getAllFilenames($filePage)) || file_exists($finalFilename)));
        }
        elseif(!is_null($file) && $file->isTemp()) {
            $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . '.' . $path_parts['extension'];
        }
        else {
            do {
                $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . ($n>0 ? '-'.$n : '') . '.' . $path_parts['extension'];
                $n++;
                $candidateBasename = pathinfo($finalFilename, PATHINFO_BASENAME);
            } while($candidateBasename !== $currentBasename && (in_array($candidateBasename, $this->getAllFilenames($filePage)) || file_exists($finalFilename)));
        }

        return $finalFilename;
    }


    // gets filenames for all files/images on the page, including inside repeaters
    private function getAllFilenames($p, $withId = false) {
        $p->of(false);
        $files = array();
        foreach($p->fields as $field) {

            if($field->type instanceof FieldtypeFile) {
                $fieldObject = $p->getUnformatted($field->name);
                if(wireCount($fieldObject)) {
                    foreach($fieldObject as $file) {
                        if($withId) {
                            $files[] = $file->name . '|' . $field->id; // add filename with respective fieldid to array
                        }
                        else {
                            $files[] = $file->name;
                        }
                    }
                }
            }
            elseif($field->type instanceof FieldtypeFieldsetPage) {
                foreach($p->{$field->name}->fields as $rf) {
                    if($rf->type instanceof FieldtypeFile) {
                        $fieldObject = $p->{$field->name}->getUnformatted($rf->name);
                        if(wireCount($fieldObject)) {
                            foreach($fieldObject as $file) {
                                if($withId) {
                                    $files[] = $file->name.'|'.$p->{$field->name}->id.'|'.$rf->id; // add filename with respective fieldid to array
                                }
                                else {
                                    $files[] = $file->name;
                                }
                            }
                        }
                    }
                }
            }
            elseif($field->type instanceof FieldtypeRepeater) {
                foreach($p->{$field->name} as $repeater) {

                    // make sure repeater item actually exists already, which is important when you have added items beyond those initially rendered.
                    // fixes this issue: https://github.com/ryancramerdesign/ProcessWire/issues/1541
                    if(!is_object($repeater) || !$repeater->id) continue;

                    foreach($repeater->fields as $rf) {
                        if($rf->type instanceof FieldtypeFile) {
                            $fieldObject = $repeater->getUnformatted($rf->name);
                            if($fieldObject && wireCount($fieldObject)) {
                                foreach($fieldObject as $file) {
                                    if(!$file) continue;
                                    if($withId) {
                                        $files[] = $file->name.'|'.$repeater->id.'|'.$rf->id; // add filename with respective repeater pageid and fieldid to array
                                    }
                                    else {
                                        $files[] = $file->name;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $files;
    }


    private function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $randomString;
    }


    private function isImgVarOf($origImage, $compareImage) {
        $escapedName = preg_quote(pathinfo($origImage, PATHINFO_FILENAME), '/');
        $escapedExt = preg_quote(pathinfo($origImage, PATHINFO_EXTENSION), '/');

        // variation name with size dimensions and optionally suffix
        $re1 = '/^'  .
            $escapedName . '\.' .            // myfile.
            '(\d+)x(\d+)' .                 // 50x50
            '([pd]\d+x\d+|[a-z]{1,2})?' .   // nw or p30x40 or d30x40
            '(?:-([-_a-z0-9]+))?' .         // -suffix1 or -suffix1-suffix2, etc.
            '\.' . $escapedExt .             // .jpg
            '$/';

        // variation name with suffix only
        $re2 = '/^' .
            $escapedName . '\.' .            // myfile.
            '-([-_a-z0-9]+)' .              // suffix1 or suffix1-suffix2, etc.
            '(?:\.' .                       // optional extras for dimensions/crop, starts with period
                '(\d+)x(\d+)' .             // optional 50x50
                '([pd]\d+x\d+|[a-z]{1,2})?' . // nw or p30x40 or d30x40
            ')?' .
            '\.' . $escapedExt .             // .jpg
            '$/';

        // if regex matches, return true
        if(preg_match($re1, $compareImage) || preg_match($re2, $compareImage)) {
            return true;
        }
        return false;
    }



    public function getModuleConfigInputfields(array $data) {

            $data = array_merge(self::getDefaultData(), $data);

            // this is a container for fields, basically like a fieldset
            $fields = new InputfieldWrapper();

            if($this->wire('modules')->isInstalled('ProcessGetVideoThumbs')) {
                $f = $this->wire('modules')->get("InputfieldCheckbox");
                $f->attr('name', 'getVideoThumbs');
                $f->label = __('Ignore Youtube and Vimeo images', __FILE__);
                $f->description = __('This prevents images added by the Get Video Thumbs module from being renamed.', __FILE__);
                $f->notes = __('Note that having this checked will prevent any images containing "youtube" or "vimeo" in the filename from being renamed.', __FILE__);
                $f->attr('checked', $data['getVideoThumbs'] == '1' ? 'checked' : '');
                $fields->add($f);
            }

            // Populate the $fieldsModel with data for each field
            $fieldsModel = array(
                    'tempDisabled' => array(
                                    'label'=>"Temporarily Disabled",
                                    'desc'=>'Check to disable this rule without deleting it.',
                                    'type'=>"_createInputfieldCheckbox",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'fieldsetname'=>'Rename Rules',
                                    'fieldsetdescription'=>"&bull; Add as many different rules as you need.\n&bull; If a rule option is left blank, the rule with be applied to all fields/templates/pages/extensions.\n&bull; Leave Filename Format blank to prevent renaming for a specific field/template/page combo, overriding a more general rule.\n&bull; Rules are processed in order, so put more specific rules before more general ones. You can drag to change the order of rules as needed.\n&bull; The following variables can be used in the filename format: ".'$page, $template, $field, and $file. '."For some of these (eg. ".'$field'."->description), if they haven't been filled out and saved prior to uploading the image, renaming won't occur on upload, but will happen on page save - if you inserted it into an RTE/HTML field before page save, then the link will be automatically updated).\n\nSome example filename formats:\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'$page->title'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'$filePage->title ($filePage will grab from the page the file is connected to - useful for repeaters)'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'mysite-{$template->name}-images'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'$field->label'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'$file->description'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'{$page->name}-{$file->filesize}-kb'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'prefix-[Y_m_d_H_i_s]-suffix (anything inside square brackets is is considered to be a PHP date format for the current date/time)'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'randstring[n] (where n is the number of characters you want in the string)'."\n&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&bull;&nbsp;".'### (custom number mask, eg. 001 if more than one image with same name on a page. This is an enhanced version of the automatic addition of numbers if required)'."\n\n&bull; If 'Rename on Save' is checked files will be renamed again each time a page is saved (admin or front-end via API). WARNING: this setting will break any direct links to the old filename in your template files. However, images inserted into RTE/HTML fields on the same page will have their links automatically updated.",
                                    'width'=>25),
                    'enabledFields' => array(
                                    'label' => "Enabled Fields",
                                    'desc' => "Select none for all fields.",
                                    'type' => "_createInputfieldAsmSelect",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width' => 25),
                    'enabledTemplates' => array(
                                    'label'=>"Enabled Templates",
                                    'desc'=>"Select none for all templates.",
                                    'type'=>"_createInputfieldAsmSelect",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'enabledPages' => array(
                                    'label'=>"Enabled Pages",
                                    'desc'=>"AND THEIR CHILDREN Select none for all pages.",
                                    'type'=>"_createInputfieldPageListSelectMultiple",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'fileExtensions' => array(
                                    'label'=>"File Extensions",
                                    'desc'=>"Comma separated (eg. png, jpg). Leave empty for all extensions.",
                                    'type'=>"_createInputfieldText",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'filenameFormat' => array(
                                    'label'=>"Filename Format",
                                    'desc'=>'eg: mysite-{$page->path} Leave empty to not rename.',
                                    'type'=>"_createInputfieldText",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'filenameLength' => array(
                                    'label'=>"Filename Length",
                                    'desc'=>'Number of characters (nearest whole word). Leave empty for no truncation.',
                                    'type'=>"_createInputfieldText",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'renameOnSave' => array(
                                    'label'=>"Rename on Save",
                                    'desc'=>'Rename again on page save. See warning above.',
                                    'type'=>"_createInputfieldCheckbox",
                                    'options' => "",
                                    'notes' => "",
                                    'fieldset'=>'renameRules',
                                    'width'=>25),
                    'ruleData' => array(
                                    'label'=>"Rule Data",
                                    'desc'=>"JSON string of the rule data",
                                    'type'=>"_createInputfieldHidden",
                                    'options' => "",
                                    'notes' => "")
            );
            // Now use $data and $fieldsModel loop to create all fields
            $fieldset = '';

            foreach($fieldsModel as $f => $fM) {
                    $type = $fM['type'];
                    $fM['width'] = isset($fM['width']) ? $fM['width'] : 100;
                    if(isset($fM['fieldset'])) {
                        if($fM['fieldset'] != $fieldset) {
                            $fieldset = $fM['fieldset'];
                            ${$fM['fieldset']} = $this->wire('modules')->get("InputfieldFieldset");
                            ${$fM['fieldset']}->label = $fM['fieldsetname'];
                            ${$fM['fieldset']}->description = $fM['fieldsetdescription'];
                            ${$fM['fieldset']}->id = str_replace(' ', '', $fM['fieldsetname']);
                            ${$fM['fieldset']}->set('collapsed', Inputfield::collapsedNo);
                        }
                        // For Jquery to work we want all rename rules fields in a wrapper of their own, so skip adding the field here
                        if($fM['fieldset'] != 'renameRules') {
                            ${$fM['fieldset']}->add(
                                self::$type($f, $fM['label'], $data[$f], $fM['desc'], $fM['options'], $fM['notes'], $fM['width'])
                            );
                        }
                        $fields->add(${$fM['fieldset']});
                    }
                    else {
                        $fields->add(
                            self::$type($f, $fM['label'], $data[$f], $fM['desc'], $fM['options'], $fM['notes'], $fM['width'])
                        );
                    }
            }

            $data['renameRules'] = !empty($data['ruleData']) ? json_decode($data['ruleData'], true) : array(0 => array('tempDisabled' => '','enabledFields' => '', 'enabledTemplates' => '', 'enabledPages' => '', 'fileExtensions' => '', 'filenameFormat' => '', 'filenameLength' => '', 'renameOnSave' => ''));
            // If we have more rules stored then load extra rows
            if(!empty($data['renameRules'])) {
                foreach ($data['renameRules'] as $k => $rule) {
                    $rulewrapper = new InputfieldWrapper();
                    $rulewrapper->add(self::_createInputfieldCheckbox('tempDisabled', 'Temporarily Disabled', $rule['tempDisabled'], $fieldsModel['tempDisabled']['desc'], '', '', 25));
                    $rulewrapper->add(self::_createInputfieldAsmSelect('enabledFields', 'Enabled Fields', $rule['enabledFields'], $fieldsModel['enabledFields']['desc'], '', '', 25, $k));
                    $rulewrapper->add(self::_createInputfieldAsmSelect('enabledTemplates', 'Enabled Templates', $rule['enabledTemplates'], $fieldsModel['enabledTemplates']['desc'], '', '', 25, $k));
                    $rulewrapper->add(self::_createInputfieldPageListSelectMultiple('enabledPages', 'Enabled Pages', $rule['enabledPages'], $fieldsModel['enabledPages']['desc'], '', '', 25, $k));
                    $rulewrapper->add(self::_createInputfieldText('fileExtensions', 'File Extensions', $rule['fileExtensions'], $fieldsModel['fileExtensions']['desc'], '', '', 25));
                    $rulewrapper->add(self::_createInputfieldText('filenameFormat', 'Filename Format', $rule['filenameFormat'], $fieldsModel['filenameFormat']['desc'], '', '', 25));
                    $rulewrapper->add(self::_createInputfieldText('filenameLength', 'Filename Length', $rule['filenameLength'], $fieldsModel['filenameLength']['desc'], '', '', 25));
                    $rulewrapper->add(self::_createInputfieldCheckbox('renameOnSave', 'Rename on Save', $rule['renameOnSave'], $fieldsModel['renameOnSave']['desc'], '', '', 25));

                    $renameRules->add($rulewrapper);
                }
            }

            return $fields;
    }


    protected function addScript($event) {
        $conf = $this->getModuleInfo();
        $this->wire('config')->scripts->add($this->wire('config')->urls->ProcessCustomUploadNames . "ProcessCustomUploadNames.js?v={$conf['version']}");
        $this->wire('config')->styles->add($this->wire('config')->urls->ProcessCustomUploadNames . "ProcessCustomUploadNames.css?v={$conf['version']}");
    }

    private function addRule($id) {
        $fields = new InputfieldWrapper();
        $fields->add($this->_createInputfieldCheckbox('tempDisabled', 'Temporarily Disabled', '', 'Check to disable this rule without deleting it.', '', '', 25));
        $fields->add($this->_createInputfieldAsmSelect('enabledFields', 'Enabled Fields', '', 'Select none for all fields.', '', '', 25, $id));
        $fields->add($this->_createInputfieldAsmSelect('enabledTemplates', 'Enabled Templates', '', 'Select none for all templates.', '', '', 25, $id));
        $fields->add($this->_createInputfieldPageListSelectMultiple('enabledPages', 'Enabled Pages', '', 'AND THEIR CHILDREN Select none for all pages.', '', '', 25, $id));
        $fields->add($this->_createInputfieldText('fileExtensions', 'File Extensions', '', 'Comma separated list (eg. png, jpg). Leave empty for all extensions.', '', '', 25));
        $fields->add($this->_createInputfieldText('filenameFormat', 'Filename Format', '', 'eg: mysite-{$page->path} Leave empty to not rename.', '', '', 25));
        $fields->add($this->_createInputfieldText('filenameLength', 'Filename Length', '', 'Number of characters (nearest whole word). Leave empty for no truncation.', '', '', 25));
        $fields->add($this->_createInputfieldCheckbox('renameOnSave', 'Rename on Save', '', 'Rename again on page save. See warning above.', '', '', 25));
        echo $fields->render();
        exit;
    }

    private function _createInputfieldText($ipName, $ipTitle, $ipValue='', $ipDesc='', $ipOptions='', $ipNotes='', $ipWidth=100, $ipRequired=false) {
        $field =  $this->wire('modules')->get("InputfieldText");
        $field->name = $ipName;
        $field->label = $ipTitle;
        $field->required = $ipRequired;
        $field->description = $ipDesc;
        $field->attr('value', $ipValue);
        $field->attr('notes', $ipNotes);
        $field->columnWidth = $ipWidth;
        return $field;
    }

    private function _createInputfieldCheckbox($ipName, $ipTitle, $ipValue='', $ipDesc='', $ipOptions='', $ipNotes='', $ipWidth=100, $ipRequired=false) {
        $field = $this->wire('modules')->get("InputfieldCheckbox");
        $field->name = $ipName;
        $field->label = $ipTitle;
        $field->label2 = ' '; // this sets the displayed label to nothing - needs the space or it reverts to displaying ->label
        $field->required = $ipRequired;
        $field->description = $ipDesc;
        $field->attr('checked', $ipValue == '1' ? 'checked' : '' );
        $field->value = $ipValue;
        $field->attr('notes', $ipNotes);
        $field->columnWidth = $ipWidth;
        return $field;
    }

    private function _createInputfieldAsmSelect($aName, $aTitle, $aValue, $aDesc='', $aOptions='', $aNotes='', $aWidth=100, $aID=1) {
        $field = $this->wire('modules')->get("InputfieldAsmSelect");
        $field->name = $aName;
        $field->label = $aTitle;
        $field->description = $aDesc;
        if($aName == 'enabledFields') {
            foreach($this->wire('fields') as $currfield) {
                if($currfield->flags & Field::flagSystem) continue;
                if($currfield->type instanceof FieldtypeFile) $field->addOption($currfield->id, $currfield->name);
            }
        }
        if($aName == 'enabledTemplates') {
            foreach($this->wire('templates') as $currtemplate) {
                if($currtemplate->name != 'user' && ($currtemplate->flags & Template::flagSystem)) continue;
                $field->addOption($currtemplate->id, $currtemplate->name);
            }
        }
        $field->attr('value', $aValue);
        $field->columnWidth = $aWidth;
        $field->setAsmSelectOption('sortable', false);
        return $field;
    }


    private function _createInputfieldPageListSelectMultiple($ipName, $ipTitle, $ipValue='', $ipDesc='', $ipOptions='', $ipNotes='', $ipWidth=100, $ipID=1) {
        $field =  $this->wire('modules')->get("InputfieldPageListSelectMultiple");
        $field->name = $ipName;
        $field->label = $ipTitle;
        $field->description = $ipDesc;
        $field->attr('value', $ipValue);
        $field->attr('id', $ipName . $ipID); // Allows us to add more of these with different IDs via AJAX
        $field->set('unselectLabel', 'Unselect');
        $field->columnWidth = $ipWidth;
        if($ipValue == 0) $field->collapsed = Inputfield::collapsedNo;
        return $field;
    }

    private function _createInputfieldHidden($tName, $tTitle, $tValue, $tDesc='', $ipOptions='') {
        $field = $this->wire('modules')->get("InputfieldHidden");
        $field->name = $tName;
        $field->label = $tTitle;
        $field->description = $tDesc;
        $field->attr('value', $tValue);
        return $field;
    }

    /**
     * Strip the trailing dedup number from a filename for comparison purposes
     * Handles both number mask (### → -001) and standard dedup (-1, -2, etc.)
     */
    private function stripDedupNumber($filepath, $filenameFormat) {
        $name = pathinfo($filepath, PATHINFO_FILENAME);
        if(strpos($filenameFormat, '#') !== false) {
            $trimNum = substr_count($filenameFormat, '#');
            return rtrim(substr($name, 0, -$trimNum), '-');
        }
        // strip trailing -N if the last segment is numeric
        return preg_replace('/-\d+$/', '', $name);
    }

    private function truncate($text, $length) {
        if(strlen($text) > $length) {
            $pos = strrpos(substr($text, 0, $length), '-');
            if($pos === false) return substr($text, 0, $length);
            return substr($text, 0, $pos);
        }
        return $text;
    }

    public function ___install() {
        $data = array();
        $module = 'ProcessCustomUploadNames';
        $this->wire('modules')->saveModuleConfigData($module, $data);
    }

}
