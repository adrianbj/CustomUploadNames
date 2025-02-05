<?php

/**
 * ProcessWire Custom Upload Names
 * by Adrian Jones
 *
 * Automatically rename file/image uploads according to a configurable format
 *
 * Copyright (C) 2024 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class ProcessCustomUploadNames extends WireData implements Module, ConfigurableModule {

    /**
     * getModuleInfo is a module required by all modules to tell ProcessWire about them
     *
     * @return array
     *
     */
    public static function getModuleInfo() {
        return array(
            'title' => __('Custom Upload Names'),
            'version' => '1.3.6',
            'author' => 'Adrian Jones',
            'summary' => __('Automatically rename file/image uploads according to a configurable format'),
            'href' => 'http://modules.processwire.com/modules/process-custom-upload-names/',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'edit'
        );
    }

    /**
     * Data as used by the get/set functions
     *
     */
    protected static $fM = array();


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
                $this->addRule($this->wire('input')->get->addRule);
            }
        }

        // only load js file if we're on the module config settings for this module, rather than any other module
        if($this->className() == $this->wire('input')->get->name) $this->addHookAfter("ProcessModule::executeEdit", $this, "addScript");


        if($this->ruleData!='') {
            // page in the admin
            $processPage = $this->wire('page');
            if($processPage->process && in_array('WirePageEditor', class_implements((string) $processPage->process))) {
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

        if(method_exists($this->wire('pages')->get($pageid), 'getForPage')) {
            $editedPage = $this->wire('pages')->get($pageid)->getForPage();
        }
        elseif($this->wire('input')->get->context == 'PageTable') {
            $editedPage = $this->wire('pages')->get("FieldtypePageTable=".$process->getPage().", include=all");
        }
        else {
            $editedPage = $this->wire('pages')->get($pageid);
        }


        // $editedPage->of(false);

        if($action == 'upload') {
            // if page belongs to a repeater or pagetable field
            if(method_exists($this->wire('pages')->get($pageid), 'getForPage') || $this->wire('input')->get->context == 'PageTable') {
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

        foreach($files as $file) {
            // if it was a repeater field updating on save, then need to get pageid of repeater field
            $repeaterPage = null;
            $elements = explode('|', $file);
            $filename = $elements[0];
            $repeaterid = isset($elements[2]) ? $elements[1] : null;
            $fieldid = isset($elements[2]) ? $elements[2] : $elements[1];

            //if($action == 'save' && (!is_object($pagefile) || is_object($pagefile) && !$pagefile->mtime) && !$repeaterid) continue;

            if($repeaterid) {
                $repeaterPage = $this->wire('pages')->get($repeaterid);
                $repeaterPage->of(false);
            }

            // quick fix to prevent this module from renaming video thumbs from GetVideoThumbs module
            if($this->wire('modules')->isInstalled('ProcessGetVideoThumbs') && $this->data['getVideoThumbs'] == 1) {
                if(strpos($filename,'youtube') !== false || strpos($filename,'vimeo') !== false) return;
            }

            $filePage = $repeaterPage ? $repeaterPage : $editedPage;
            $filePage->of(false);

            // ruleData is a json string that we need to turn into an object
            $rules = json_decode($this->ruleData);

            // iterate through each of the rename rules
            foreach ($rules as $rule) {

                foreach(explode("|",$editedPage->parents) as $parent) {
                    if(isset($rule->enabledPages)) $parentEnabled = in_array($parent, $rule->enabledPages) || in_array(1, $rule->enabledPages) ? true : false;
                }

                // all the conditions to not rename
                if($rule->tempDisabled == '1') continue;
                if(is_array($rule->enabledFields) && count($rule->enabledFields) && !in_array($fieldid, $rule->enabledFields)) continue; // if fields set and this is not a selected field
                if(is_array($rule->enabledTemplates) && count($rule->enabledTemplates) && !in_array($editedPage->template->id, $rule->enabledTemplates)) continue;
                if(isset($rule->enabledPages) && $rule->enabledPages[0] != '' && !in_array($editedPage->id, $rule->enabledPages) && !$parentEnabled) continue;
                if($rule->fileExtensions != '' && !in_array(pathinfo($filename, PATHINFO_EXTENSION), explode(",", trim(str_replace(', ',',',$rule->fileExtensions))))) continue; // if fileExtensions is set and the uploaded file does not match

                // for these next rules, break rather than continue because these are not specifity rules. No match is a positive result and so we don't want to test the next rule.
                // if repeater page but image has no repeater ID need to break to prevent this problem: https://processwire.com/talk/topic/4865-custom-upload-names/?do=findComment&comment=191410
                // if(method_exists($this->wire('pages')->get($pageid), 'getForPage') && !$repeaterid) break;
                if($rule->filenameFormat == '') break; // don't attempt to rename if the filename format field is empty
                // check if filename has -n extension and if so we do a rename on save to remove the -n if we can
                preg_match('/(.*)-\d$/', pathinfo($filename, PATHINFO_FILENAME), $matches);
                if($rule->renameOnSave != '1' && $action == 'save' && strpos(pathinfo($filename, PATHINFO_FILENAME),'-upload-tmp') === false && count($matches) === 0) break; // -upload-tmp set when the eval'd filename format is not available yet because field is empty.

                // build the new filename
                $oldFilename = $filePage->filesManager()->path() . basename($filename);
                $newFilename = $this->createNewFilename($oldFilename, $rule->filenameFormat, $rule->filenameLength, $editedPage, $fieldid, $repeaterPage);

                if($oldFilename == $newFilename) continue;

                // rename the file
                if($action == 'upload') {

                    if(file_exists($oldFilename)) {
                        $pagefile->rename($newFilename);
                        // set image as temp because the rename method removes this
                        // image will have temp status removed once page is saved
                        if(!$field->overwrite && $method == 'admin') $pagefile->isTemp(true);
                    }
                }
                elseif($action == 'save') { // saving from admin or api

                    // checks to prevent renaming on page save when there is no need because the filename won't change.
                    // this is mainly to prevent -n or #nnnn numbers from changing on each page save.
                    if(strpos($rule->filenameFormat, '#') !== false) {
                        $trimNum = substr_count($rule->filenameFormat, '#');
                        $filenameSansNum = trim(substr(pathinfo($oldFilename, PATHINFO_FILENAME), 0, -$trimNum), '_') . '.' . pathinfo($oldFilename, PATHINFO_EXTENSION);
                        $newFilenameSansNum = trim(substr(pathinfo($newFilename, PATHINFO_FILENAME), 0, -$trimNum), '_') . '.' . pathinfo($newFilename, PATHINFO_EXTENSION);
                    }
                    else {
                        $parts = explode("_", pathinfo($oldFilename, PATHINFO_FILENAME));
                        $filenameNum = end($parts);
                        if(is_numeric($filenameNum)) {
                            $filenameSansNum = str_replace('_'.$filenameNum, '', $oldFilename);
                        }
                        else {
                            $filenameSansNum = $oldFilename;
                        }
                        $parts = explode("_", pathinfo($newFilename, PATHINFO_FILENAME));
                        $newFilenameNum = end($parts);
                        if(is_numeric($newFilenameNum)) {
                            $newFilenameSansNum = str_replace('_'.$newFilenameNum, '', $newFilename);
                        }
                        else {
                            $newFilenameSansNum = $newFilename;
                        }
                    }

                    if($filenameSansNum == $newFilenameSansNum && file_exists($oldFilename)) continue;

                    if($oldFilename != $newFilename) {
                        $field = $this->wire('fields')->get($fieldid);
                        $file = $filePage->$field->get("name=$filename");
                        $filePage->$field->trackChange("filename");
                        if($field->type instanceof FieldtypeImage) {
                            if(!is_null($file)) {
                                foreach($file->getVariations() as $imageVariation) {
                                    rename($imageVariation->filename, pathinfo($newFilename, PATHINFO_DIRNAME) . '/' . pathinfo($newFilename, PATHINFO_FILENAME) . str_replace(pathinfo($oldFilename, PATHINFO_FILENAME), '', pathinfo($imageVariation->filename, PATHINFO_FILENAME)) . '.' . pathinfo($oldFilename, PATHINFO_EXTENSION));
                                }
                            }
                            $this->replaceRteLinks($newFilename, $oldFilename);
                        }

                        if(!is_null($file)) {
                            $file->rename(pathinfo($newFilename, PATHINFO_BASENAME));
                            $filePage->save($field->name);
                        }
                    }
                }
                break; // need to break out of $rules foreach once there has been a match and the file has been renamed.
            }

        }

    }

    private function replaceRteLinks($newFilename, $oldFilename) {
        $textareaFields = $this->wire('fields')->find("type=FieldtypeTextarea|FieldtypeTextareaLanguage");
        $fieldsStr = $textareaFields->implode('|', 'name');
        $oldRelativeUrl = str_replace($this->wire('config')->paths->root, '', $oldFilename);
        $oldRelativeUrlSansExt = str_replace(pathinfo($oldFilename, PATHINFO_EXTENSION), '', $oldRelativeUrl);
        foreach($this->wire('pages')->find("$fieldsStr%=$oldRelativeUrlSansExt, include=all") as $p) {
            foreach($textareaFields as $taf) {
                if($p->$taf != '') {
                    $pagedom = new DOMDocument();
                    libxml_use_internal_errors(true);
                    // add <cun> as fake root element so that domdocument can parse the html properly and not add extra closing </p> tag
                    $pagedom->loadHTML('<?xml encoding="utf-8" ?><cun>' . $p->$taf . '</cun>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED | LIBXML_SCHEMA_CREATE);
                    $pagedom = $this->replaceRteLink($pagedom, $newFilename, $oldFilename, 'a', 'href');
                    $pagedom = $this->replaceRteLink($pagedom, $newFilename, $oldFilename, 'img', 'src');
                    // remove fake root element
                    $html = str_replace(['<cun>', '</cun>', '<?xml encoding="utf-8" ?>'], '', $pagedom->saveHtml());
                    $p->of(false);
                    $p->$taf = $html;
                    libxml_clear_errors();
                    $p->save($taf);
                }
            }
        }
    }

    private function replaceRteLink($pagedom, $newFilename, $oldFilename, $tag, $attr) {
        foreach($pagedom->getElementsByTagName($tag) as $link) {
            // if $link is the same image as (or a variation of) the one we are currently looping through ($oldFilename), then rename it
            if(pathinfo($oldFilename, PATHINFO_BASENAME) == pathinfo($link->getAttribute($attr), PATHINFO_BASENAME) || $this->isImgVarOf(pathinfo($oldFilename, PATHINFO_BASENAME), pathinfo($link->getAttribute($attr), PATHINFO_BASENAME))) {
                $parts = explode("/", pathinfo($newFilename, PATHINFO_DIRNAME));
                $pid = end($parts);
                $link->setAttribute($attr, $this->wire('pages')->get($pid)->filesManager()->url() . pathinfo($newFilename, PATHINFO_FILENAME) . str_replace(pathinfo($oldFilename, PATHINFO_FILENAME), '', pathinfo($link->getAttribute($attr), PATHINFO_FILENAME)) . '.' . pathinfo($oldFilename, PATHINFO_EXTENSION));
            }
        }
        return $pagedom;
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


        // Grab filename format and eval it. I am thinking about ditching the eval approach and going with a template style system
        // The two commented out options allow for full flexibility (the user can use php functions etc, but makes formatting more complicated)
        // $newname = $this->sanitizer->pageName(eval($newname), true);
        // $newname = $this->sanitizer->pageName(eval("return $newname;"), true);

        $page->of(true); // turned this on for allowing datetime field outputformatting to come through in filenames, rather than unixtimestamps

        // check if the field is a language alternate field and if so, set the user language to this language
        if($this->wire('languages')) {
            $arr = explode('_', $field->name);
            $fileLanguageName = end($arr);
            $language = $this->wire('languages')->get($fileLanguageName);
            if($language->id) $this->wire('user')->language = $language;
        }


        if(strpos($newname,'randstring') !== false) { // process the length from random string request
            preg_match("/\[(.*?)\]/", $newname, $length);
            $newname = str_replace('randstring['.$length[1].']', $this->generateRandomString($length[1]), $newname);
        }
        elseif(strpos($newname,'[') !== false) { // expecting a date format string for formatting the current datetime
            preg_match("/\[(.*?)\]/", $newname, $dateformat_array);
            $newname = str_replace($dateformat_array[0], date($dateformat_array[1]), $newname);
        }

        // get the eval'd filename
        $evalednewname = @eval('return "'.$newname.'";');
        $page->of(false); // not sure if turning off is really necessarily, but seems safer

        // if any of the eval'd variables (PW fields etc) are empty we should treat this as a temp name until fields are populated
        preg_match_all('/\{[^\}]*\}/', $newname, $matches);
        $blankField = false;
        foreach($matches[0] as $pwfield) {
            if(@eval('return "'.$pwfield.'";') == '') {
                $blankField = true;
                break; // if a blank PW field found break now
            }
        }

        if($blankField || $evalednewname == '') {
            if(strpos($path_parts['filename'],'-upload-tmp') === false) {
                $newname = $path_parts['filename'] . '-upload-tmp'; // this allows the filename to be renamed on page save if the field for the format wasn't available at upload
            }
            else {
                $newname = $path_parts['filename'];
            }
        }
        else {
            $newname = $evalednewname;
        }

        // remove any encoded entities
        $newname = $this->wire('sanitizer')->unentities($newname);

        // truncate final new name before checking to see if "-n" needs to be appended
        if($filenameLength != '') $newname = $this->truncate($newname, $filenameLength);

        $n = 0;
        // if a number mask (### etc) is supplied in the filename format
        if(strpos($newname,'#') !== false) {
            do {
                $n++;
                $custom_n = str_pad($n, substr_count($newname, '#')+1, '0', STR_PAD_LEFT);
                $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . '_'. $custom_n . '.' . $path_parts['extension'];
            } while(in_array(pathinfo($finalFilename, PATHINFO_BASENAME), $this->getAllFilenames($filePage)) || file_exists($finalFilename) || file_exists(str_replace($path_parts['dirname'], $filePage->filesManager()->path(), $finalFilename)));
        }
        elseif(!is_null($file) && $file->isTemp()) {
            $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . '.' . $path_parts['extension'];
        }
        else {
            do {
                $finalFilename = $path_parts['dirname'] . '/' . str_replace(array('_', '.'), '-', $this->wire('sanitizer')->pageNameTranslate($newname)) . ($n>0 ? '_'.$n : '') . '.' . $path_parts['extension'];
                $n++;
            } while(in_array(pathinfo($finalFilename, PATHINFO_BASENAME), $this->getAllFilenames($filePage)) || file_exists($finalFilename) || file_exists(str_replace($path_parts['dirname'], $filePage->filesManager()->path(), $finalFilename)));
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
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }


    private function isImgVarOf($origImage, $compareImage) {
        // variation name with size dimensions and optionally suffix
        $re1 = '/^'  .
            pathinfo($origImage, PATHINFO_FILENAME) . '\.' .      // myfile.
            '(\d+)x(\d+)' .                 // 50x50
            '([pd]\d+x\d+|[a-z]{1,2})?' .   // nw or p30x40 or d30x40
            '(?:-([-_a-z0-9]+))?' .         // -suffix1 or -suffix1-suffix2, etc.
            '\.' . pathinfo($origImage, PATHINFO_EXTENSION) .           // .jpg
            '$/';

        // variation name with suffix only
        $re2 = '/^' .
            pathinfo($origImage, PATHINFO_FILENAME) . '\.' .      // myfile.
            '-([-_a-z0-9]+)' .              // suffix1 or suffix1-suffix2, etc.
            '(?:\.' .                       // optional extras for dimensions/crop, starts with period
                '(\d+)x(\d+)' .             // optional 50x50
                '([pd]\d+x\d+|[a-z]{1,2})?' . // nw or p30x40 or d30x40
            ')?' .
            '\.' . pathinfo($origImage, PATHINFO_EXTENSION) .           // .jpg
            '$/';

        // if regex matches, return true
        if(preg_match($re1, $compareImage) || preg_match($re2, $compareImage)) {
            return true;
        }
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

            foreach($fieldsModel as $f=>$fM) {
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
        wire("config")->scripts->add($this->wire('config')->urls->ProcessCustomUploadNames . "ProcessCustomUploadNames.js?v={$conf['version']}");
        wire("config")->styles->add($this->wire('config')->urls->ProcessCustomUploadNames . "ProcessCustomUploadNames.css?v={$conf['version']}");
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

    private function _createInputfieldText($ipName, $ipTitle, $ipValue='', $ipDesc='', $ipOptions='', $ipNotes='', $ipWidth, $ipRequired=false) {
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

    private function _createInputfieldCheckbox($ipName, $ipTitle, $ipValue='', $ipDesc='', $ipOptions='', $ipNotes='', $ipWidth, $ipRequired=false) {
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
        // $field->sortable = false; // this doesn't work - is there an alternative of setAsmSelectOption('sortable', false); that works for PageListSelectMultiple fields ?
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

    private function truncate($text, $length) {
        if(strlen($text) > $length) {
            return substr($text, 0, strrpos(substr( $text, 0, $length), '-' ));
        }
        else {
            return $text;
        }
    }

    public function ___install() {
        $data = array();
        $module = 'ProcessCustomUploadNames';
        $this->wire('modules')->saveModuleConfigData($module, $data);
    }

}
