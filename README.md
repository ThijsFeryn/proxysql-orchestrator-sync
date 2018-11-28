# proxysql-orchestrator-sync
Synchronize MySQL master and slave data from [GitHub Orchestrator](http://github.com/github/orchestrator/)'s API into [ProxySQL](https://github.com/sysown/proxysql).

When nodes fail, GitHub Orchestrator will mark these nodes as down. The synchronization script will remove failed nodes from its rotation. 

## How does it work?
This PHP script will poll the [Orchestrator API](https://github.com/github/orchestrator/blob/master/docs/using-the-web-api.md) at regular intervals and compare the state of the nodes to what is known in ProxySQL.

If the state differs, the ProxySQL servers will be flushed and re-populated with Orchestrator data.

The `/api/cluster/alias/yourClusterName` endpoint is used as the "source of truth". This API call returns a collection of JSON objects, where each object represents a server.

The following object properties are used in the decision making process:

* `IsDowntimed`: is the server deliberately put in downtime?
* `IsLastCheckValid`: did the server pass its last health check?

And specifically for slave servers, the following properties are used:

* `Slave_SQL_Running`: is the SQL replication mechanism running on a slave?
* `Slave_IO_Running`: is the I/O replication mechanism running on a slave?

If a server doesn't meet the assertions above, it is not taken into rotation and will not be inserted in ProxySQL upon the next synchronisation run.

## When will the script perform a synchronization run?

The `pollInterval` configuration item defines the frequency of the runs in seconds. By default this is 10 seconds.

However, this script also offers a webhook that explicitly forces a re-population of the ProxySQL servers. By default the scripts binds to port `8080` on all network interfaces, but the binding can be configured in the `binding` configuration item.

## Why, where and when to use the webhook?

The webhook was specifically built to be called by the [Orchestrator hooks](https://github.com/github/orchestrator/blob/master/docs/configuration-recovery.md#hooks).

In your [orchestrator.conf.json](https://github.com/github/orchestrator/blob/master/conf/orchestrator-sample.conf.json) file, you could for example add the following call:

```
...
{
 "PostFailoverProcesses": [
   "curl -s http://proxy-sync-host:8080/"
 ],
}
...
```

After a failover, you can force the synchronization script to run, but calling the webhook. You can also perform this call in the `PreFailoverProcesses` hook.