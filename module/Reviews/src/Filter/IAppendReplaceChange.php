<?php

namespace Reviews\Filter;

use Application\Factory\InvokableService;

/**
 * Values and responsibilities for the append/replace change filter
 */
interface IAppendReplaceChange extends InvokableService
{
    const CHANGE_ID    = "changeId";
    const PENDING      = "pending";
    const FILTER       = "appendReplaceFilter";
    const MODE_APPEND  = "append";
    const MODE_REPLACE = "replace";
}
