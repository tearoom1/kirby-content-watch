(function() {
  "use strict";
  function _typeof(o) {
    "@babel/helpers - typeof";
    return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(o2) {
      return typeof o2;
    } : function(o2) {
      return o2 && "function" == typeof Symbol && o2.constructor === Symbol && o2 !== Symbol.prototype ? "symbol" : typeof o2;
    }, _typeof(o);
  }
  function requiredArgs(required, args) {
    if (args.length < required) {
      throw new TypeError(required + " argument" + (required > 1 ? "s" : "") + " required, but only " + args.length + " present");
    }
  }
  function toDate(argument) {
    requiredArgs(1, arguments);
    var argStr = Object.prototype.toString.call(argument);
    if (argument instanceof Date || _typeof(argument) === "object" && argStr === "[object Date]") {
      return new Date(argument.getTime());
    } else if (typeof argument === "number" || argStr === "[object Number]") {
      return new Date(argument);
    } else {
      if ((typeof argument === "string" || argStr === "[object String]") && typeof console !== "undefined") {
        console.warn("Starting with v2.0.0-beta.1 date-fns doesn't accept strings as date arguments. Please use `parseISO` to parse strings. See: https://github.com/date-fns/date-fns/blob/master/docs/upgradeGuide.md#string-arguments");
        console.warn(new Error().stack);
      }
      return /* @__PURE__ */ new Date(NaN);
    }
  }
  var defaultOptions = {};
  function getDefaultOptions() {
    return defaultOptions;
  }
  function getTimezoneOffsetInMilliseconds(date) {
    var utcDate = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate(), date.getHours(), date.getMinutes(), date.getSeconds(), date.getMilliseconds()));
    utcDate.setUTCFullYear(date.getFullYear());
    return date.getTime() - utcDate.getTime();
  }
  function compareAsc(dirtyDateLeft, dirtyDateRight) {
    requiredArgs(2, arguments);
    var dateLeft = toDate(dirtyDateLeft);
    var dateRight = toDate(dirtyDateRight);
    var diff = dateLeft.getTime() - dateRight.getTime();
    if (diff < 0) {
      return -1;
    } else if (diff > 0) {
      return 1;
    } else {
      return diff;
    }
  }
  function differenceInCalendarMonths(dirtyDateLeft, dirtyDateRight) {
    requiredArgs(2, arguments);
    var dateLeft = toDate(dirtyDateLeft);
    var dateRight = toDate(dirtyDateRight);
    var yearDiff = dateLeft.getFullYear() - dateRight.getFullYear();
    var monthDiff = dateLeft.getMonth() - dateRight.getMonth();
    return yearDiff * 12 + monthDiff;
  }
  function differenceInMilliseconds(dateLeft, dateRight) {
    requiredArgs(2, arguments);
    return toDate(dateLeft).getTime() - toDate(dateRight).getTime();
  }
  var roundingMap = {
    ceil: Math.ceil,
    round: Math.round,
    floor: Math.floor,
    trunc: function trunc(value) {
      return value < 0 ? Math.ceil(value) : Math.floor(value);
    }
    // Math.trunc is not supported by IE
  };
  var defaultRoundingMethod = "trunc";
  function getRoundingMethod(method) {
    return roundingMap[defaultRoundingMethod];
  }
  function endOfDay(dirtyDate) {
    requiredArgs(1, arguments);
    var date = toDate(dirtyDate);
    date.setHours(23, 59, 59, 999);
    return date;
  }
  function endOfMonth(dirtyDate) {
    requiredArgs(1, arguments);
    var date = toDate(dirtyDate);
    var month = date.getMonth();
    date.setFullYear(date.getFullYear(), month + 1, 0);
    date.setHours(23, 59, 59, 999);
    return date;
  }
  function isLastDayOfMonth(dirtyDate) {
    requiredArgs(1, arguments);
    var date = toDate(dirtyDate);
    return endOfDay(date).getTime() === endOfMonth(date).getTime();
  }
  function differenceInMonths(dirtyDateLeft, dirtyDateRight) {
    requiredArgs(2, arguments);
    var dateLeft = toDate(dirtyDateLeft);
    var dateRight = toDate(dirtyDateRight);
    var sign = compareAsc(dateLeft, dateRight);
    var difference = Math.abs(differenceInCalendarMonths(dateLeft, dateRight));
    var result;
    if (difference < 1) {
      result = 0;
    } else {
      if (dateLeft.getMonth() === 1 && dateLeft.getDate() > 27) {
        dateLeft.setDate(30);
      }
      dateLeft.setMonth(dateLeft.getMonth() - sign * difference);
      var isLastMonthNotFull = compareAsc(dateLeft, dateRight) === -sign;
      if (isLastDayOfMonth(toDate(dirtyDateLeft)) && difference === 1 && compareAsc(dirtyDateLeft, dateRight) === 1) {
        isLastMonthNotFull = false;
      }
      result = sign * (difference - Number(isLastMonthNotFull));
    }
    return result === 0 ? 0 : result;
  }
  function differenceInSeconds(dateLeft, dateRight, options) {
    requiredArgs(2, arguments);
    var diff = differenceInMilliseconds(dateLeft, dateRight) / 1e3;
    return getRoundingMethod()(diff);
  }
  var formatDistanceLocale = {
    lessThanXSeconds: {
      one: "less than a second",
      other: "less than {{count}} seconds"
    },
    xSeconds: {
      one: "1 second",
      other: "{{count}} seconds"
    },
    halfAMinute: "half a minute",
    lessThanXMinutes: {
      one: "less than a minute",
      other: "less than {{count}} minutes"
    },
    xMinutes: {
      one: "1 minute",
      other: "{{count}} minutes"
    },
    aboutXHours: {
      one: "about 1 hour",
      other: "about {{count}} hours"
    },
    xHours: {
      one: "1 hour",
      other: "{{count}} hours"
    },
    xDays: {
      one: "1 day",
      other: "{{count}} days"
    },
    aboutXWeeks: {
      one: "about 1 week",
      other: "about {{count}} weeks"
    },
    xWeeks: {
      one: "1 week",
      other: "{{count}} weeks"
    },
    aboutXMonths: {
      one: "about 1 month",
      other: "about {{count}} months"
    },
    xMonths: {
      one: "1 month",
      other: "{{count}} months"
    },
    aboutXYears: {
      one: "about 1 year",
      other: "about {{count}} years"
    },
    xYears: {
      one: "1 year",
      other: "{{count}} years"
    },
    overXYears: {
      one: "over 1 year",
      other: "over {{count}} years"
    },
    almostXYears: {
      one: "almost 1 year",
      other: "almost {{count}} years"
    }
  };
  var formatDistance$1 = function formatDistance2(token, count, options) {
    var result;
    var tokenValue = formatDistanceLocale[token];
    if (typeof tokenValue === "string") {
      result = tokenValue;
    } else if (count === 1) {
      result = tokenValue.one;
    } else {
      result = tokenValue.other.replace("{{count}}", count.toString());
    }
    if (options !== null && options !== void 0 && options.addSuffix) {
      if (options.comparison && options.comparison > 0) {
        return "in " + result;
      } else {
        return result + " ago";
      }
    }
    return result;
  };
  function buildFormatLongFn(args) {
    return function() {
      var options = arguments.length > 0 && arguments[0] !== void 0 ? arguments[0] : {};
      var width = options.width ? String(options.width) : args.defaultWidth;
      var format = args.formats[width] || args.formats[args.defaultWidth];
      return format;
    };
  }
  var dateFormats = {
    full: "EEEE, MMMM do, y",
    long: "MMMM do, y",
    medium: "MMM d, y",
    short: "MM/dd/yyyy"
  };
  var timeFormats = {
    full: "h:mm:ss a zzzz",
    long: "h:mm:ss a z",
    medium: "h:mm:ss a",
    short: "h:mm a"
  };
  var dateTimeFormats = {
    full: "{{date}} 'at' {{time}}",
    long: "{{date}} 'at' {{time}}",
    medium: "{{date}}, {{time}}",
    short: "{{date}}, {{time}}"
  };
  var formatLong = {
    date: buildFormatLongFn({
      formats: dateFormats,
      defaultWidth: "full"
    }),
    time: buildFormatLongFn({
      formats: timeFormats,
      defaultWidth: "full"
    }),
    dateTime: buildFormatLongFn({
      formats: dateTimeFormats,
      defaultWidth: "full"
    })
  };
  var formatRelativeLocale = {
    lastWeek: "'last' eeee 'at' p",
    yesterday: "'yesterday at' p",
    today: "'today at' p",
    tomorrow: "'tomorrow at' p",
    nextWeek: "eeee 'at' p",
    other: "P"
  };
  var formatRelative = function formatRelative2(token, _date, _baseDate, _options) {
    return formatRelativeLocale[token];
  };
  function buildLocalizeFn(args) {
    return function(dirtyIndex, options) {
      var context = options !== null && options !== void 0 && options.context ? String(options.context) : "standalone";
      var valuesArray;
      if (context === "formatting" && args.formattingValues) {
        var defaultWidth = args.defaultFormattingWidth || args.defaultWidth;
        var width = options !== null && options !== void 0 && options.width ? String(options.width) : defaultWidth;
        valuesArray = args.formattingValues[width] || args.formattingValues[defaultWidth];
      } else {
        var _defaultWidth = args.defaultWidth;
        var _width = options !== null && options !== void 0 && options.width ? String(options.width) : args.defaultWidth;
        valuesArray = args.values[_width] || args.values[_defaultWidth];
      }
      var index = args.argumentCallback ? args.argumentCallback(dirtyIndex) : dirtyIndex;
      return valuesArray[index];
    };
  }
  var eraValues = {
    narrow: ["B", "A"],
    abbreviated: ["BC", "AD"],
    wide: ["Before Christ", "Anno Domini"]
  };
  var quarterValues = {
    narrow: ["1", "2", "3", "4"],
    abbreviated: ["Q1", "Q2", "Q3", "Q4"],
    wide: ["1st quarter", "2nd quarter", "3rd quarter", "4th quarter"]
  };
  var monthValues = {
    narrow: ["J", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"],
    abbreviated: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    wide: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
  };
  var dayValues = {
    narrow: ["S", "M", "T", "W", "T", "F", "S"],
    short: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"],
    abbreviated: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    wide: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]
  };
  var dayPeriodValues = {
    narrow: {
      am: "a",
      pm: "p",
      midnight: "mi",
      noon: "n",
      morning: "morning",
      afternoon: "afternoon",
      evening: "evening",
      night: "night"
    },
    abbreviated: {
      am: "AM",
      pm: "PM",
      midnight: "midnight",
      noon: "noon",
      morning: "morning",
      afternoon: "afternoon",
      evening: "evening",
      night: "night"
    },
    wide: {
      am: "a.m.",
      pm: "p.m.",
      midnight: "midnight",
      noon: "noon",
      morning: "morning",
      afternoon: "afternoon",
      evening: "evening",
      night: "night"
    }
  };
  var formattingDayPeriodValues = {
    narrow: {
      am: "a",
      pm: "p",
      midnight: "mi",
      noon: "n",
      morning: "in the morning",
      afternoon: "in the afternoon",
      evening: "in the evening",
      night: "at night"
    },
    abbreviated: {
      am: "AM",
      pm: "PM",
      midnight: "midnight",
      noon: "noon",
      morning: "in the morning",
      afternoon: "in the afternoon",
      evening: "in the evening",
      night: "at night"
    },
    wide: {
      am: "a.m.",
      pm: "p.m.",
      midnight: "midnight",
      noon: "noon",
      morning: "in the morning",
      afternoon: "in the afternoon",
      evening: "in the evening",
      night: "at night"
    }
  };
  var ordinalNumber = function ordinalNumber2(dirtyNumber, _options) {
    var number = Number(dirtyNumber);
    var rem100 = number % 100;
    if (rem100 > 20 || rem100 < 10) {
      switch (rem100 % 10) {
        case 1:
          return number + "st";
        case 2:
          return number + "nd";
        case 3:
          return number + "rd";
      }
    }
    return number + "th";
  };
  var localize = {
    ordinalNumber,
    era: buildLocalizeFn({
      values: eraValues,
      defaultWidth: "wide"
    }),
    quarter: buildLocalizeFn({
      values: quarterValues,
      defaultWidth: "wide",
      argumentCallback: function argumentCallback(quarter) {
        return quarter - 1;
      }
    }),
    month: buildLocalizeFn({
      values: monthValues,
      defaultWidth: "wide"
    }),
    day: buildLocalizeFn({
      values: dayValues,
      defaultWidth: "wide"
    }),
    dayPeriod: buildLocalizeFn({
      values: dayPeriodValues,
      defaultWidth: "wide",
      formattingValues: formattingDayPeriodValues,
      defaultFormattingWidth: "wide"
    })
  };
  function buildMatchFn(args) {
    return function(string) {
      var options = arguments.length > 1 && arguments[1] !== void 0 ? arguments[1] : {};
      var width = options.width;
      var matchPattern = width && args.matchPatterns[width] || args.matchPatterns[args.defaultMatchWidth];
      var matchResult = string.match(matchPattern);
      if (!matchResult) {
        return null;
      }
      var matchedString = matchResult[0];
      var parsePatterns = width && args.parsePatterns[width] || args.parsePatterns[args.defaultParseWidth];
      var key = Array.isArray(parsePatterns) ? findIndex(parsePatterns, function(pattern) {
        return pattern.test(matchedString);
      }) : findKey(parsePatterns, function(pattern) {
        return pattern.test(matchedString);
      });
      var value;
      value = args.valueCallback ? args.valueCallback(key) : key;
      value = options.valueCallback ? options.valueCallback(value) : value;
      var rest = string.slice(matchedString.length);
      return {
        value,
        rest
      };
    };
  }
  function findKey(object, predicate) {
    for (var key in object) {
      if (object.hasOwnProperty(key) && predicate(object[key])) {
        return key;
      }
    }
    return void 0;
  }
  function findIndex(array, predicate) {
    for (var key = 0; key < array.length; key++) {
      if (predicate(array[key])) {
        return key;
      }
    }
    return void 0;
  }
  function buildMatchPatternFn(args) {
    return function(string) {
      var options = arguments.length > 1 && arguments[1] !== void 0 ? arguments[1] : {};
      var matchResult = string.match(args.matchPattern);
      if (!matchResult) return null;
      var matchedString = matchResult[0];
      var parseResult = string.match(args.parsePattern);
      if (!parseResult) return null;
      var value = args.valueCallback ? args.valueCallback(parseResult[0]) : parseResult[0];
      value = options.valueCallback ? options.valueCallback(value) : value;
      var rest = string.slice(matchedString.length);
      return {
        value,
        rest
      };
    };
  }
  var matchOrdinalNumberPattern = /^(\d+)(th|st|nd|rd)?/i;
  var parseOrdinalNumberPattern = /\d+/i;
  var matchEraPatterns = {
    narrow: /^(b|a)/i,
    abbreviated: /^(b\.?\s?c\.?|b\.?\s?c\.?\s?e\.?|a\.?\s?d\.?|c\.?\s?e\.?)/i,
    wide: /^(before christ|before common era|anno domini|common era)/i
  };
  var parseEraPatterns = {
    any: [/^b/i, /^(a|c)/i]
  };
  var matchQuarterPatterns = {
    narrow: /^[1234]/i,
    abbreviated: /^q[1234]/i,
    wide: /^[1234](th|st|nd|rd)? quarter/i
  };
  var parseQuarterPatterns = {
    any: [/1/i, /2/i, /3/i, /4/i]
  };
  var matchMonthPatterns = {
    narrow: /^[jfmasond]/i,
    abbreviated: /^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i,
    wide: /^(january|february|march|april|may|june|july|august|september|october|november|december)/i
  };
  var parseMonthPatterns = {
    narrow: [/^j/i, /^f/i, /^m/i, /^a/i, /^m/i, /^j/i, /^j/i, /^a/i, /^s/i, /^o/i, /^n/i, /^d/i],
    any: [/^ja/i, /^f/i, /^mar/i, /^ap/i, /^may/i, /^jun/i, /^jul/i, /^au/i, /^s/i, /^o/i, /^n/i, /^d/i]
  };
  var matchDayPatterns = {
    narrow: /^[smtwf]/i,
    short: /^(su|mo|tu|we|th|fr|sa)/i,
    abbreviated: /^(sun|mon|tue|wed|thu|fri|sat)/i,
    wide: /^(sunday|monday|tuesday|wednesday|thursday|friday|saturday)/i
  };
  var parseDayPatterns = {
    narrow: [/^s/i, /^m/i, /^t/i, /^w/i, /^t/i, /^f/i, /^s/i],
    any: [/^su/i, /^m/i, /^tu/i, /^w/i, /^th/i, /^f/i, /^sa/i]
  };
  var matchDayPeriodPatterns = {
    narrow: /^(a|p|mi|n|(in the|at) (morning|afternoon|evening|night))/i,
    any: /^([ap]\.?\s?m\.?|midnight|noon|(in the|at) (morning|afternoon|evening|night))/i
  };
  var parseDayPeriodPatterns = {
    any: {
      am: /^a/i,
      pm: /^p/i,
      midnight: /^mi/i,
      noon: /^no/i,
      morning: /morning/i,
      afternoon: /afternoon/i,
      evening: /evening/i,
      night: /night/i
    }
  };
  var match = {
    ordinalNumber: buildMatchPatternFn({
      matchPattern: matchOrdinalNumberPattern,
      parsePattern: parseOrdinalNumberPattern,
      valueCallback: function valueCallback(value) {
        return parseInt(value, 10);
      }
    }),
    era: buildMatchFn({
      matchPatterns: matchEraPatterns,
      defaultMatchWidth: "wide",
      parsePatterns: parseEraPatterns,
      defaultParseWidth: "any"
    }),
    quarter: buildMatchFn({
      matchPatterns: matchQuarterPatterns,
      defaultMatchWidth: "wide",
      parsePatterns: parseQuarterPatterns,
      defaultParseWidth: "any",
      valueCallback: function valueCallback(index) {
        return index + 1;
      }
    }),
    month: buildMatchFn({
      matchPatterns: matchMonthPatterns,
      defaultMatchWidth: "wide",
      parsePatterns: parseMonthPatterns,
      defaultParseWidth: "any"
    }),
    day: buildMatchFn({
      matchPatterns: matchDayPatterns,
      defaultMatchWidth: "wide",
      parsePatterns: parseDayPatterns,
      defaultParseWidth: "any"
    }),
    dayPeriod: buildMatchFn({
      matchPatterns: matchDayPeriodPatterns,
      defaultMatchWidth: "any",
      parsePatterns: parseDayPeriodPatterns,
      defaultParseWidth: "any"
    })
  };
  var locale = {
    code: "en-US",
    formatDistance: formatDistance$1,
    formatLong,
    formatRelative,
    localize,
    match,
    options: {
      weekStartsOn: 0,
      firstWeekContainsDate: 1
    }
  };
  function assign(target, object) {
    if (target == null) {
      throw new TypeError("assign requires that input parameter not be null or undefined");
    }
    for (var property in object) {
      if (Object.prototype.hasOwnProperty.call(object, property)) {
        target[property] = object[property];
      }
    }
    return target;
  }
  function cloneObject(object) {
    return assign({}, object);
  }
  var MINUTES_IN_DAY = 1440;
  var MINUTES_IN_ALMOST_TWO_DAYS = 2520;
  var MINUTES_IN_MONTH = 43200;
  var MINUTES_IN_TWO_MONTHS = 86400;
  function formatDistance(dirtyDate, dirtyBaseDate, options) {
    var _ref, _options$locale;
    requiredArgs(2, arguments);
    var defaultOptions2 = getDefaultOptions();
    var locale$1 = (_ref = (_options$locale = options === null || options === void 0 ? void 0 : options.locale) !== null && _options$locale !== void 0 ? _options$locale : defaultOptions2.locale) !== null && _ref !== void 0 ? _ref : locale;
    if (!locale$1.formatDistance) {
      throw new RangeError("locale must contain formatDistance property");
    }
    var comparison = compareAsc(dirtyDate, dirtyBaseDate);
    if (isNaN(comparison)) {
      throw new RangeError("Invalid time value");
    }
    var localizeOptions = assign(cloneObject(options), {
      addSuffix: Boolean(options === null || options === void 0 ? void 0 : options.addSuffix),
      comparison
    });
    var dateLeft;
    var dateRight;
    if (comparison > 0) {
      dateLeft = toDate(dirtyBaseDate);
      dateRight = toDate(dirtyDate);
    } else {
      dateLeft = toDate(dirtyDate);
      dateRight = toDate(dirtyBaseDate);
    }
    var seconds = differenceInSeconds(dateRight, dateLeft);
    var offsetInSeconds = (getTimezoneOffsetInMilliseconds(dateRight) - getTimezoneOffsetInMilliseconds(dateLeft)) / 1e3;
    var minutes = Math.round((seconds - offsetInSeconds) / 60);
    var months;
    if (minutes < 2) {
      if (options !== null && options !== void 0 && options.includeSeconds) {
        if (seconds < 5) {
          return locale$1.formatDistance("lessThanXSeconds", 5, localizeOptions);
        } else if (seconds < 10) {
          return locale$1.formatDistance("lessThanXSeconds", 10, localizeOptions);
        } else if (seconds < 20) {
          return locale$1.formatDistance("lessThanXSeconds", 20, localizeOptions);
        } else if (seconds < 40) {
          return locale$1.formatDistance("halfAMinute", 0, localizeOptions);
        } else if (seconds < 60) {
          return locale$1.formatDistance("lessThanXMinutes", 1, localizeOptions);
        } else {
          return locale$1.formatDistance("xMinutes", 1, localizeOptions);
        }
      } else {
        if (minutes === 0) {
          return locale$1.formatDistance("lessThanXMinutes", 1, localizeOptions);
        } else {
          return locale$1.formatDistance("xMinutes", minutes, localizeOptions);
        }
      }
    } else if (minutes < 45) {
      return locale$1.formatDistance("xMinutes", minutes, localizeOptions);
    } else if (minutes < 90) {
      return locale$1.formatDistance("aboutXHours", 1, localizeOptions);
    } else if (minutes < MINUTES_IN_DAY) {
      var hours = Math.round(minutes / 60);
      return locale$1.formatDistance("aboutXHours", hours, localizeOptions);
    } else if (minutes < MINUTES_IN_ALMOST_TWO_DAYS) {
      return locale$1.formatDistance("xDays", 1, localizeOptions);
    } else if (minutes < MINUTES_IN_MONTH) {
      var days = Math.round(minutes / MINUTES_IN_DAY);
      return locale$1.formatDistance("xDays", days, localizeOptions);
    } else if (minutes < MINUTES_IN_TWO_MONTHS) {
      months = Math.round(minutes / MINUTES_IN_MONTH);
      return locale$1.formatDistance("aboutXMonths", months, localizeOptions);
    }
    months = differenceInMonths(dateRight, dateLeft);
    if (months < 12) {
      var nearestMonth = Math.round(minutes / MINUTES_IN_MONTH);
      return locale$1.formatDistance("xMonths", nearestMonth, localizeOptions);
    } else {
      var monthsSinceStartOfYear = months % 12;
      var years = Math.floor(months / 12);
      if (monthsSinceStartOfYear < 3) {
        return locale$1.formatDistance("aboutXYears", years, localizeOptions);
      } else if (monthsSinceStartOfYear < 9) {
        return locale$1.formatDistance("overXYears", years, localizeOptions);
      } else {
        return locale$1.formatDistance("almostXYears", years + 1, localizeOptions);
      }
    }
  }
  function normalizeComponent(scriptExports, render, staticRenderFns, functionalTemplate, injectStyles, scopeId, moduleIdentifier, shadowMode) {
    var options = typeof scriptExports === "function" ? scriptExports.options : scriptExports;
    if (render) {
      options.render = render;
      options.staticRenderFns = staticRenderFns;
      options._compiled = true;
    }
    return {
      exports: scriptExports,
      options
    };
  }
  const _sfc_main = {
    props: {
      files: Array,
      historyEntries: Array,
      retentionDays: {
        type: Number,
        default: 30
      },
      retentionCount: {
        type: Number,
        default: 10
      },
      lockedPages: {
        type: Array,
        default: () => []
      },
      enableRestore: {
        type: Boolean,
        default: false
      }
    },
    data() {
      return {
        isLoading: false,
        search: "",
        filteredFiles: [],
        expandedFiles: [],
        restoreTarget: null,
        showOnlyPages: true,
        currentPage: 1,
        pageSize: 10,
        pageSizeOptions: [
          { text: "10 per page", value: 10 },
          { text: "20 per page", value: 20 },
          { text: "50 per page", value: 50 }
        ],
        tab: "content"
        // Default tab is content
      };
    },
    created() {
      this.filteredFiles = this.files || [];
      this.filterFiles();
    },
    computed: {
      totalPages() {
        return Math.max(1, Math.ceil(this.filteredFiles.length / (this.pageSize || 10)));
      },
      paginationStart() {
        return (this.currentPage - 1) * (this.pageSize || 10);
      },
      paginatedFiles() {
        const start = this.paginationStart;
        const end = start + (this.pageSize || 10);
        return this.filteredFiles.slice(start, end);
      },
      items() {
        return this.filteredFiles.map((file) => {
          var _a, _b;
          const modifiedDate = new Date(file.modified * 1e3);
          const timeAgo = formatDistance(modifiedDate, /* @__PURE__ */ new Date(), { addSuffix: true });
          const editorName = ((_a = file.editor) == null ? void 0 : _a.name) || ((_b = file.editor) == null ? void 0 : _b.email) || "Unknown";
          return {
            id: file.id,
            text: file.title,
            info: `${editorName} / ${file.modified_formatted} (${timeAgo})`,
            link: file.panel_url,
            icon: "page",
            options: [{
              icon: "edit",
              click: () => this.open(file.id)
            }]
          };
        });
      },
      lockItems() {
        const items = [];
        this.lockedPages.forEach((lock) => {
          items.push({
            text: '<span class="k-content-watch-file-path"><strong>' + lock.title + "</strong><br>" + lock.id + "</span>",
            info: lock.user + " <br> " + lock.date + " (" + this.formatRelative(lock.time) + ")",
            options: [{
              icon: "edit",
              click: () => this.open(lock.id)
            }]
          });
        });
        return items;
      }
    },
    methods: {
      refresh() {
        this.isLoading = true;
        window.location.reload();
      },
      open(id) {
        const file = this.filteredFiles.find((f) => f.id === id);
        this.openFile(file);
      },
      openFile(file) {
        if (file == null ? void 0 : file.panel_url) {
          window.location.href = file.panel_url;
        }
      },
      toggleFileExpand(id) {
        const index = this.expandedFiles.indexOf(id);
        if (index === -1) {
          this.expandedFiles.push(id);
        } else {
          this.expandedFiles.splice(index, 1);
        }
      },
      filterFiles() {
        const searchLower = this.search.toLowerCase();
        let filtered = this.files;
        if (this.showOnlyPages) {
          filtered = filtered.filter((file) => file.panel_url && file.panel_url.indexOf("/files/") === -1 && !file.is_media_file);
        }
        this.filteredFiles = filtered.filter(
          (file) => file.title.toLowerCase().includes(searchLower) || file.path.toLowerCase().includes(searchLower)
        );
        this.currentPage = 1;
      },
      toggleShowOnlyPages() {
        this.showOnlyPages = true;
        this.filterFiles();
      },
      toggleShowAll() {
        this.showOnlyPages = false;
        this.filterFiles();
      },
      prevPage() {
        if (this.currentPage > 1) {
          this.currentPage--;
        }
        return false;
      },
      nextPage() {
        if (this.currentPage < this.totalPages) {
          this.currentPage++;
        }
        return false;
      },
      changePageSize(size) {
        let newSize;
        if (typeof size === "object" && size !== null) {
          newSize = size.value || 10;
        } else {
          newSize = parseInt(size, 10) || 10;
        }
        this.pageSize = newSize;
        this.currentPage = 1;
      },
      formatRelative(date) {
        if (typeof date === "string") {
          return formatDistance(new Date(date), /* @__PURE__ */ new Date(), {
            addSuffix: true
          });
        }
        return formatDistance(new Date(date * 1e3), /* @__PURE__ */ new Date(), {
          addSuffix: true
        });
      },
      confirmRestore(file, entry) {
        if (!this.enableRestore) return;
        this.restoreTarget = { file, entry };
        this.$refs.restoreDialog.open();
      },
      async restoreContent() {
        if (!this.enableRestore || !this.restoreTarget) return;
        const { file, entry } = this.restoreTarget;
        this.isLoading = true;
        try {
          const response = await this.$api.post("/content-watch/restore", {
            dirPath: file.dir_path,
            fileKey: file.uid,
            timestamp: entry.time
          });
          if (response.status === "success") {
            this.refresh();
          } else {
            this.$store.dispatch("notification/error", response.message || "Failed to restore content");
          }
        } catch (error) {
          this.$store.dispatch("notification/error", "Error restoring content: " + (error.message || "Unknown error"));
        } finally {
          this.isLoading = false;
          this.restoreTarget = null;
        }
      }
    }
  };
  var _sfc_render = function render() {
    var _a, _b, _c2;
    var _vm = this, _c = _vm._self._c;
    return _c("k-panel-inside", { staticClass: "k-content-watch-view" }, [_c("k-header", { staticClass: "k-section-header" }, [_c("div", { staticClass: "k-content-watch-tabs" }, [_c("k-button-group", [_c("k-button", { class: { "k-button-active": _vm.tab === "content" }, attrs: { "icon": "edit-line" }, on: { "click": function($event) {
      _vm.tab = "content";
    } } }, [_vm._v(" Content Changes ")]), _c("k-button", { class: { "k-button-active": _vm.tab === "locked" }, attrs: { "icon": "lock" }, on: { "click": function($event) {
      _vm.tab = "locked";
    } } }, [_vm._v(" Locked Pages ")])], 1)], 1)]), _vm.tab === "content" ? _c("section", { staticClass: "k-content-watch-section" }, [_vm.files.length ? _c("k-grid", [_c("k-column", { attrs: { "width": "1/2" } }, [_c("k-input", { staticClass: "k-content-watch-search", attrs: { "type": "text", "placeholder": _vm.$t("search") + "...", "icon": "search" }, on: { "input": _vm.filterFiles }, model: { value: _vm.search, callback: function($$v) {
      _vm.search = $$v;
    }, expression: "search" } })], 1), _c("k-column", { staticClass: "k-content-watch-buttons", attrs: { "width": "1/2" } }, [_c("k-button-group", [_c("k-button", { class: { "k-button-active": _vm.showOnlyPages }, attrs: { "icon": "page" }, on: { "click": _vm.toggleShowOnlyPages } }, [_vm._v("Pages only")]), _c("k-button", { class: { "k-button-active": !_vm.showOnlyPages }, attrs: { "icon": "file-document" }, on: { "click": _vm.toggleShowAll } }, [_vm._v("All files")]), _c("k-button", { attrs: { "icon": "refresh" }, on: { "click": _vm.refresh } })], 1)], 1)], 1) : _vm._e(), _vm.files.length && _vm.paginatedFiles.length ? _c("div", { staticClass: "k-content-watch-files" }, _vm._l(_vm.paginatedFiles, function(file, index) {
      return _c("div", { key: file.id, staticClass: "k-content-watch-file", class: { "k-content-watch-file-open": _vm.expandedFiles.includes(file.id) } }, [_c("div", { staticClass: "k-content-watch-file-header", on: { "click": function($event) {
        return _vm.toggleFileExpand(file.id);
      } } }, [_c("div", { staticClass: "k-content-watch-file-info" }, [_c("span", { staticClass: "k-content-watch-file-path" }, [_c("strong", [_vm._v(_vm._s(file.title))]), _c("br"), _vm._v(_vm._s(file.path_short) + " ")]), _c("span", { staticClass: "k-content-watch-file-editor" }, [_vm._v(" " + _vm._s(file.editor.name || file.editor.email || "Unknown")), _c("br"), _vm._v(" " + _vm._s(_vm.formatRelative(file.modified)) + " ")])]), _c("div", { staticClass: "k-content-watch-file-actions" }, [_c("k-button", { class: { "k-button-rotated": _vm.expandedFiles.includes(file.id) }, attrs: { "icon": "angle-down" } }), _c("k-button", { attrs: { "icon": "edit" }, on: { "click": function($event) {
        $event.stopPropagation();
        return _vm.openFile(file);
      } } })], 1)]), _vm.expandedFiles.includes(file.id) ? _c("div", { staticClass: "k-content-watch-file-timeline" }, [file.history && file.history.length > 0 ? _c("div", { staticClass: "k-timeline-list" }, _vm._l(file.history, function(entry, entryIndex) {
        return _c("div", { key: entryIndex, staticClass: "k-timeline-item" }, [_c("div", { staticClass: "k-timeline-item-version" }, [_vm._v(" v" + _vm._s(entry.version) + " ")]), _c("div", { staticClass: "k-timeline-item-language" }, [_vm._v(" " + _vm._s(entry.language) + " ")]), _c("div", { staticClass: "k-timeline-item-time" }, [_vm._v(" " + _vm._s(entry.time_formatted) + " ")]), _c("div", { staticClass: "k-timeline-item-time-rel" }, [_vm._v(" " + _vm._s(_vm.formatRelative(entry.time)) + " ")]), _c("span", { staticClass: "k-timeline-item-editor-label" }, [_vm._v(" " + _vm._s(entry.restored_from ? "restored by" : "edited by") + " ")]), _c("span", { staticClass: "k-timeline-item-editor" }, [_vm._v(" " + _vm._s(entry.editor.name || entry.editor.email || "Unknown") + " ")]), _c("div", { staticClass: "k-timeline-item-actions" }, [_vm.enableRestore && entry.has_snapshot && entryIndex > 0 ? _c("k-button", { staticClass: "k-restore-button", attrs: { "icon": "refresh", "title": "Restore this version" }, on: { "click": function($event) {
          $event.stopPropagation();
          return _vm.confirmRestore(file, entry);
        } } }) : _vm._e()], 1), _c("div", { staticClass: "k-timeline-item-line" })]);
      }), 0) : _c("k-empty", { attrs: { "icon": "history", "text": "No history entries found" } }), _c("div", { staticClass: "k-timeline-footer" }, [_c("span", [_vm._v("Showing changes for the last " + _vm._s(_vm.retentionDays) + " days (max " + _vm._s(_vm.retentionCount) + ")")])])], 1) : _vm._e()]);
    }), 0) : _vm._e(), _vm.files.length && _vm.filteredFiles.length ? _c("div", { staticClass: "k-content-watch-pagination" }, [_c("div", { staticClass: "k-content-watch-pagination-info" }, [_vm._v(" Showing " + _vm._s(_vm.paginationStart + 1) + " - " + _vm._s(Math.min(_vm.paginationStart + _vm.pageSize, _vm.filteredFiles.length)) + " of " + _vm._s(_vm.filteredFiles.length) + " items ")]), _c("div", { staticClass: "k-content-watch-pagination-controls" }, [_c("k-button-group", [_c("k-button", { attrs: { "disabled": _vm.currentPage <= 1, "icon": "angle-left" }, on: { "click": function($event) {
      $event.stopPropagation();
      $event.preventDefault();
      return _vm.prevPage.apply(null, arguments);
    } } }, [_vm._v("Previous")]), _c("span", { staticClass: "k-content-watch-pagination-page-info" }, [_vm._v(_vm._s(_vm.currentPage) + " / " + _vm._s(_vm.totalPages))]), _c("k-button", { attrs: { "disabled": _vm.currentPage >= _vm.totalPages, "icon": "angle-right", "icon-after": "" }, on: { "click": function($event) {
      $event.stopPropagation();
      $event.preventDefault();
      return _vm.nextPage.apply(null, arguments);
    } } }, [_vm._v("Next")])], 1)], 1), _c("div", { staticClass: "k-content-watch-pagination-pagesize" }, [_c("k-select-field", { attrs: { "value": _vm.pageSize, "options": _vm.pageSizeOptions }, on: { "input": _vm.changePageSize } })], 1)]) : _vm._e(), _vm.files.length && !_vm.filteredFiles.length ? _c("k-empty", { attrs: { "icon": "page", "text": _vm.$t("no.files.found") } }) : _vm._e(), !_vm.files.length ? _c("k-empty", { attrs: { "icon": "page", "text": "No content change data available" } }) : _vm._e(), _vm.isLoading ? _c("k-loader") : _vm._e()], 1) : _vm._e(), _vm.tab === "locked" ? _c("section", { staticClass: "k-content-watch-section" }, [_vm.lockedPages.length ? _c("k-collection", { staticClass: "k-content-watch-locked", attrs: { "items": _vm.lockItems } }) : _c("k-empty", { attrs: { "icon": "lock", "text": "No locked pages found" } })], 1) : _vm._e(), _vm.enableRestore ? _c("k-dialog", { ref: "restoreDialog", attrs: { "button": _vm.$t("restore"), "theme": "positive", "icon": "refresh" }, on: { "submit": _vm.restoreContent } }, [_c("k-text", [_vm._v("Are you sure you want to restore this version?")]), _vm.restoreTarget ? _c("k-text", [_c("strong", [_vm._v("File:")]), _vm._v(" " + _vm._s((_a = _vm.restoreTarget.file) == null ? void 0 : _a.title)), _c("br"), _c("strong", [_vm._v("Version:")]), _vm._v(" " + _vm._s((_b = _vm.restoreTarget.entry) == null ? void 0 : _b.time_formatted) + " (" + _vm._s(_vm.formatRelative((_c2 = _vm.restoreTarget.entry) == null ? void 0 : _c2.time)) + ") ")]) : _vm._e(), _c("k-text", [_vm._v("This will overwrite the current content with this previous version.")])], 1) : _vm._e()], 1);
  };
  var _sfc_staticRenderFns = [];
  _sfc_render._withStripped = true;
  var __component__ = /* @__PURE__ */ normalizeComponent(
    _sfc_main,
    _sfc_render,
    _sfc_staticRenderFns
  );
  __component__.options.__file = "/var/www/html/site/plugins/content-watch/js/components/ContentWatch.vue";
  const ContentWatch = __component__.exports;
  panel.plugin("tearoom1/content-watch", {
    components: {
      "content-watch": ContentWatch
    }
  });
})();
