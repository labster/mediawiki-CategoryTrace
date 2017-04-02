class CategoryTrails {

    public static function onOutputPageMakeCategoryLinks( &$out, $categories, &$links ) {

        # See OutputPage.php
        # But probably only find pages for type 'normal' to avoid hidden (see Skin.php)
        # The only line we need to override is the last line, to set links to have more surrounding text
        foreach ( $categories as $category => $type ) {
            // array keys will cast numeric category names to ints, so cast back to string
            $category = (string)$category;
            $origcategory = $category;
            $title = Title::makeTitleSafe( NS_CATEGORY, $category );
            if ( !$title ) {
                continue;
            }
            $wgContLang->findVariantLink( $category, $title, true );
            if ( $category != $origcategory && array_key_exists( $category, $categories ) ) {
                continue;
            }
            $text = $wgContLang->convertHtml( $title->getText() );
            $out->mCategories[] = $title->getText();
            $out->mCategoryLinks[$type][] = Linker::link( $title, $text );
        }

        return true;
    }

    # Another function here to register a formatting module (one cat per line)
    # or do that via registration?


    function fetchNeighboringPages ( Title $page, $categories ) {
        if ($wgDBtype == 'mysql') {
            return this->fetchNeighboringPagesMysql( $page, $categories );
        }
        else {
            return this->fetchNeighboringPagesDefaultDB( $page, $categories );
        }
    }

    function fetchNeighboringPagesDefaultDB ( Title $page, $categories ) {

        $dbr = wfgetDB( DB_SLAVE );

        # Get sortkey, which is how category table sorts (usually uppercase)
        $page_sortkey = Collation::singleton()->getSortKey(
			$page->getCategorySortkey( $prefix ) );


        foreach ($categories as $cat_name) {

            # Unfortunately there's not really a cheap way to get this information
            # without using a subselect.  The naive approach would be fetching all
            # members of the category instead, and sorting here, but that would be
            # a lot more data sent
            #
            # This won't work correctly when two pages share the same sortkey,
            # *and* are in the same category but that doesn't seem terribly common.
            # (And is probably a symptom of structural problems in your wiki.)

            $subselect_format = '(SELECT cl_from, cl_to, %s AS d FROM categorylinks '.
                'WHERE cl_to = %s AND cl_sortkey %s %s ORDER BY cl_sortkey %s LIMIT 1)';


            $queries[] = sprintf($subselect_format,
                -1, $dbr->addQuotes($cat_name), '<', $dbr->addQuotes($page_sortkey), 'DESC');
            $queries[] = sprintf($subselect_format,
                1,  $dbr->addQuotes($cat_name), '>', $dbr->addQuotes($page_sortkey), 'ASC');

        }

        $subselect = join( ' UNION ALL ', $queries );

        $res = $dbr->query( sprintf( 'SELECT page_title, page_id, page_namespace, cl_to, d '.
            'FROM (%s) AS x LEFT JOIN page ON page_id = x.cl_from',
            $subselect );

       # Complete query looks like:
       # SELECT page_title, page_id, cl_to, d from (
       #   (SELECT cl_from, cl_to, 1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey > 'SHAFT' ORDER BY cl_sortkey ASC limit 1)
       #    union all
       #   (SELECT cl_from, cl_to, -1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey < 'SHAFT' ORDER BY cl_sortkey DESC limit 1)
       # ... ) AS x LEFT JOIN page ON page_id = x.cl_from;
       #
       # select page_title, page_id, cl_to, d from ( (select cl_from, cl_to, 1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey > 'SHAFT' order by cl_sortkey asc limit 1) union all (select cl_from, cl_to, -1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey < 'SHAFT' order by cl_sortkey desc limit 1) UNION ALL (select cl_from, cl_to, 1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey > 'Self-Demonstrating_Article' order by cl_sortkey asc limit 1) union all (select cl_from, cl_to, -1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey < 'Self-Demonstrating_Article' order by cl_sortkey desc limit 1) ) AS x left join page on page_id = x.cl_from;


        $prevpage = array();
        $nextpage = array();
        foreach ( $res as $row ) {
            $title = Title::newFromRow( $row )
            if ( $row->d > 0 ) {
                $nextpage[ $row->cl_to ] = $title;
            }
            else {
                $prevpage[ $row->cl_to ] = $title;
            }
        }
        
        return $prevpage, $nextpage;
            
    }

    function fetchNeighboringPagesMysql ( Title $page, $categories ) {

        $dbr = wfgetDB( DB_SLAVE );

        # Get sortkey, which is how category table sorts (usually uppercase)
        $page_sortkey = Collation::singleton()->getSortKey(
			$page->getCategorySortkey( $prefix ) );

        # SELECT cl_to, current_title, prev_title FROM (select @prev as previous, @prev_title as prev_title, @prev_cat as prev_cat, @prev_cat := cl_to AS cl_to, @prev := cl_sortkey as current, @prev_title := page_title as current_title from ( select @prev := null, @prev_title := null, @prev_cat := null ) as i, categorylinks as cl LEFT JOIN page ON page_id = cl_from WHERE cl_to IN ('Blaxploitation', 'Aria') ORDER BY cl_to, cl_sortkey) as foo WHERE prev_cat = cl_to AND (cl_to = 'Blaxploitation' AND ( previous = 'VAMPIRE IN BROOKLYN' OR current = 'VAMPIRE IN BROOKLYN')) OR (cl_to = 'Aria' AND 'ARIA/YMMV' IN ( previous, current ));


        # I'm sorry to anyone who has to security review this, but there are not
        # a lot of ways to do this without killing the DB with tons of queries.
        # This one works on mysql only.
        #
        # OK, so the strategy here is to make a table, and then use variables to
        # fake self right join it.  This way we only go through the table once,
        # and we use the index for the cl_to field to cull the herd.
        # Then we look for rows with the same sortkey as the current page in both fields,
        # because we're interested in both the row before or after.

        # Add conditions for the outer query
        $conditions = [];
        foreach ($categories as $cat_name) {
            # This might give incorrect results if a page and a category have the same name
            # and are members of the same category
            $conditions[] = sprintf( "(cl_to = %s AND %s IN (previous, current))",
                $dbr->addQuotes($cat_name), $dbr->addQuotes($page_sortkey)
            );
        }
        $outer_conditions = join( ' OR ', $conditions );
    
        $db_categories = $dbr->makeList( ['cl_to' => $categories] );

        $res = $dbr->query(
            "SELECT cl_to, current_title, page_namespace, prev_title
                FROM
                (SELECT @prev as previous, @prev_title as prev_title, @prev_cat as prev_cat,
                     @prev_cat := cl_to AS cl_to,
                     @prev := cl_sortkey as current,
                     @prev_title := page_title as current_title
                    FROM ( select @prev := null, @prev_title := null, @prev_cat := null ) as i,
                        categorylinks as cl LEFT JOIN page ON page_id = cl_from
                    WHERE $db_categories
                    ORDER BY cl_to, cl_sortkey
                ) AS j
             WHERE prev_cat = cl_to AND ($outer_conditions);"
        );

        $prevpage = array();
        $nextpage = array();
        foreach ( $res as $row ) {
            $prev_title = Title::makeTitle( $row->page_namespace, $row->prev_title );
            if ( $page->equals( $prev_title ) ) {
                $nextpage[ $row->cl_to ] = Title::makeTitle( $row->page_namespace, $row->current_title );
            }
            else {
                $prevpage[ $row->cl_to ] = $prev_title;
            }
        }

        return $prevpage, $nextpage;
            
    }


}
