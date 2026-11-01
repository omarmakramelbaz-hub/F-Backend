#!/usr/bin/env python3
"""Read-only OS/ABI/capacity probe; never reads application credentials or a DB."""
import json
import os
from pathlib import Path
import platform
import re
import shutil
import subprocess
import sys

ENV={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','LC_ALL':'C'}
GIB=1024**3
MIN_AVAILABLE=int(5.5*GIB)


def text(path):
    try:return Path(path).read_text()[:262144]
    except OSError:return ''


def run(argv):
    try:
        done=subprocess.run(argv,capture_output=True,text=True,timeout=5,env=ENV)
        return done.stdout[:262144] if done.returncode==0 else ''
    except (OSError,subprocess.TimeoutExpired):return ''


def memory_available():
    match=re.search(r'^MemAvailable:\s+(\d+) kB',text('/proc/meminfo'),re.M)
    available=int(match[1])*1024 if match else 0
    memberships=text('/proc/self/cgroup').splitlines()
    v2=next((line[3:] for line in memberships if line.startswith('0::')),None)
    roots=[Path('/sys/fs/cgroup')/v2.lstrip('/'),Path('/sys/fs/cgroup')] if v2 is not None else []
    for root in roots:
        raw=text(root/'memory.max').strip()
        if raw:
            if raw.isdigit():
                current=text(root/'memory.current').strip()
                if not current.isdigit():return 0
                values=dict((key,int(value)) for key,value in re.findall(r'^(\w+)\s+(\d+)',text(root/'memory.stat'),re.M))
                reclaim=max(0,values.get('inactive_file',0)+values.get('active_file',0)
                    -values.get('file_dirty',0)-values.get('file_writeback',0)-values.get('unevictable',0))
                available=min(available,max(0,int(raw)-int(current)+reclaim))
            elif raw!='max':return 0
            return available
    legacy=next((line.split(':',2)[2] for line in memberships if len(line.split(':',2))==3 and 'memory' in line.split(':',2)[1].split(',')),None)
    if legacy is not None:
        for root in [Path('/sys/fs/cgroup/memory')/legacy.lstrip('/'),Path('/sys/fs/cgroup/memory')]:
            raw=text(root/'memory.limit_in_bytes').strip()
            if not raw:continue
            used=text(root/'memory.usage_in_bytes').strip()
            if not raw.isdigit() or not used.isdigit():return 0
            if int(raw)<2**60:
                values=dict((key,int(value)) for key,value in re.findall(r'^(\w+)\s+(\d+)',text(root/'memory.stat'),re.M))
                get=lambda key:values.get('total_'+key,values.get(key,0))
                reclaim=max(0,get('inactive_file')+get('active_file')-get('dirty')-get('writeback')-get('unevictable'))
                available=min(available,max(0,int(raw)-int(used)+reclaim))
            break
    return available


def version(value):
    found=re.search(r'(\d+(?:\.\d+)+)',value or '')
    return tuple(map(int,found[1].split('.'))) if found else ()


def collect():
    release={}
    for line in text('/etc/os-release').splitlines():
        if '=' in line:
            key,value=line.split('=',1)
            if key in {'ID','VERSION_ID','PRETTY_NAME'}:release[key]=value.strip('"')[:100]
    cache=run(['/sbin/ldconfig','-p']) or run(['/usr/sbin/ldconfig','-p'])
    def library(name):
        rows=[row for row in cache.splitlines() if row.strip().startswith(name+' ') and '=>' in row]
        rows.sort(key=lambda row:'x86-64' not in row)
        return rows[0].split('=>',1)[1].strip() if rows else None
    raw=b'';standard=library('libstdc++.so.6')
    if standard:
        try:
            with open(standard,'rb') as stream:raw=stream.read(16777216)
        except OSError:pass
    def maximum(prefix):
        entries=re.findall(prefix.encode()+rb'_(\d+\.\d+(?:\.\d+)?)',raw)
        return max((value.decode() for value in entries),key=version,default=None)
    memory={key:int(value)*1024 for key,value in re.findall(r'^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+) kB',text('/proc/meminfo'),re.M)}
    tools={name:shutil.which(name,path=ENV['PATH']) for name in ['python3','ffmpeg','ffprobe','systemctl','nice','ionice','curl','cmake','g++','make']}
    encoders=run([tools['ffmpeg'],'-hide_banner','-encoders']) if tools['ffmpeg'] else ''
    disks={}
    for path in ['/home/fasakha/public_html','/usr/local/lib','/var/tmp']:
        if Path(path).is_dir():disks[path]=shutil.disk_usage(path).free
    workers={}
    properties=['LoadState','ActiveState','User','Nice','CPUQuotaPerSecUSec','MemoryMax','MemoryHigh','TasksMax','CPUWeight']
    for unit in ['go-owner-dashboards.service','fasakhansta-customer-crm.service','go-erp-live-updates.service','ea-php82-php-fpm.service']:
        output=run(['systemctl','show','--no-pager','--property='+','.join(properties),unit])
        fields=dict(line.split('=',1) for line in output.splitlines() if '=' in line)
        if fields.get('LoadState') not in {None,'not-found'}:workers[unit]=fields
    return {'read_only':True,'os':release,'architecture':platform.machine(),'python':list(sys.version_info[:3]),
        'cpu_count':os.cpu_count(),'cpu_allowed':len(os.sched_getaffinity(0)) if hasattr(os,'sched_getaffinity') else os.cpu_count(),
        'memory_bytes':memory,'effective_available_memory_bytes':memory_available(),'disk_free_bytes':disks,
        'libraries':{'glibc':os.confstr('CS_GNU_LIBC_VERSION') if 'CS_GNU_LIBC_VERSION' in os.confstr_names else None,
            'glibcxx':maximum('GLIBCXX'),'cxxabi':maximum('CXXABI'),'libgomp':bool(library('libgomp.so.1')),
            'libssl3':bool(library('libssl.so.3')),'libcrypto3':bool(library('libcrypto.so.3')),
            'openssl':run(['openssl','version']).strip()[:300]},'tools':{key:bool(value) for key,value in tools.items()},
        'mp3_encoder':'libmp3lame' in encoders,'worker_limits':workers}


def assessment(report):
    reasons=[];libraries=report['libraries']
    if report['architecture']!='x86_64':reasons.append('ARCHITECTURE')
    for key,minimum in [('glibc',(2,34)),('glibcxx',(3,4,30)),('cxxabi',(1,3,13))]:
        if version(libraries.get(key))<minimum:reasons.append(key.upper())
    for key in ['libgomp','libssl3','libcrypto3']:
        if not libraries.get(key):reasons.append(key.upper())
    if tuple(report['python'])<(3,8):reasons.append('PYTHON')
    if (report['cpu_allowed'] or 0)<2:reasons.append('CPU')
    if report['effective_available_memory_bytes']<MIN_AVAILABLE:reasons.append('MEMORY')
    if not report['tools']['ffmpeg'] or not report['tools']['ffprobe'] or not report['mp3_encoder']:reasons.append('AUDIO_TOOLS')
    if report['disk_free_bytes'].get('/usr/local/lib',0)<4*GIB:reasons.append('DISK')
    return {'safe':not reasons,'reasons':reasons,'min_available_memory_bytes':MIN_AVAILABLE,
        'measured_peak_memory_gib':4.38,'worker_memory_limit_gib':6,'worker_threads':2}


if __name__=='__main__':
    result=collect();result['generation']=assessment(result);print(json.dumps(result,indent=2))
