var Couch = (function($) {
    var mod = {};
    var localItems;
    var remoteItems;
    var localCounts;
    var remoteCounts;

    mod.init = function(host, itemDB, countDB) {
        localItems = new PouchDB(itemDB);
        remoteItems = new PouchDB('http://' + host + ':5984/' + itemDB);
        localItems.sync(remoteItems, {
            live: true,
            retry: true
        }).on('changed', function(change) {
        }).on('paused', function(info) {
        }).on('active', function(info) {
        }).on('error', function(err) {
        });

        localCounts = new PouchDB(countDB);
        remoteCounts = new PouchDB('http://' + host + ':5984/' + countDB);
        localCounts.sync(remoteCounts, {
            live: true,
            retry: true
        }).on('changed', function(change) {
        }).on('paused', function(info) {
        }).on('active', function(info) {
        }).on('error', function(err) {
        });
    };

    var padUPC = function(upc) {
        return '0000000000000'.substring(0, 13 - upc.length) + upc;
    };

    const defaultItem = {
        brand: "",
        description: "",
        vendor: "",
        units: 0
    };

    mod.getItem = function(upc, callback) {
        localItems.query('upc/upc', { 
            key:padUPC(upc)
        }).then(function (result) {
            if (result.total_rows > 0) {
                callback(result.rows[0].value);
            } else {
                callback(Object.assign({ upc:upc }, defaultItem));
            }
        }).catch(function (err) {
            callback(Object.assign({ upc:upc }, defaultItem));
        });
    };

    mod.getCount = function(upc, storeID, sectionID, callback) {
        const key = { upc:padUPC(upc), storeID:storeID, sectionID:sectionID };
        localCounts.query('entries/by_composite', {
            key:key
        }).then(function (result) {
            if (result.total_rows > 0) {
                callback(result.rows[0].value);
            } else {
                callback(Object.assign({ quantity:0 }, key));
            }
        }).catch(function (err) {
            callback(Object.assign({ quantity:0 }, key));
        });
    };

    mod.save = function(doc) {
        if ('_id' in doc) {
            localCounts.put(doc)
                .catch(function (err) {
                });
        } else {
            localCounts.post(doc)
                .catch(function (err) {
                });
        }
    }; 

    return mod;

}(jQuery));
