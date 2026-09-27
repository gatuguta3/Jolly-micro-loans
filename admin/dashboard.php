<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

ob_start();
?>

<!-- ============ Page heading ============ -->
<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Dashboard</h1>
      <p class="text-muted mb-0">Monitor performance, sales, users, and support from one clean workspace.</p>
    </div>
  </div>
  <div class="heading-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button">
      <i class="bi bi-download" aria-hidden="true"></i> Export
    </button>
    <button class="btn btn-primary btn-sm" type="button">
      <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report
    </button>
  </div>
</div>

<!-- ============ Top metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Dashboard metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Principal issued</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">Ksh 48,240</div>
      <div class="metric-meta">
        <span class="text-success">+12.5%</span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Total Interest expected</span>
        <span class="metric-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">1,284</div>
      <div class="metric-meta">
        <span class="text-success">+8.2%</span>
        <span>new orders</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Amount collected</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">Ksh 48,240</div>
      <div class="metric-meta">
        <span class="text-success">+12.5%</span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Outstanding balance</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">Ksh 48,240</div>
      <div class="metric-meta">
        <span class="text-success">+12.5%</span>
        <span>from last month</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Loans metrics ============ -->
<section class="row g-3 mt-1" aria-label="Loans metrics">

  <div class="col-12">
    <h1 class="h3 mb-1">Loans</h1>
    <p class="text-muted mb-0">Monitor performance, sales, users, and support from one clean workspace.</p>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Collection rate</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">12.5%</div>
      <div class="metric-meta">
        <span class="text-success">Ksh 250000</span>
        <span>Collected</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Active loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">8,742</div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Cleared loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">8,742</div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Overdue loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">8,742</div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Defaulted loans</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">36</div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Loans due today</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">36</div>
      <div class="metric-meta">
        <span class="text-danger">3 urgent</span>
        <span>Check them out</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Total loan value due</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">360000</div>
      <div class="metric-meta">
        <span class="text-danger">13 loans</span>
        <span>Check them out</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Charts / Team activity / Performance ============ -->
<section class="row g-3 mt-1">

  <div class="col-12 col-xl-8">
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
            <span>Principal issued</span>
          </h2>
          <p class="text-muted mb-0">Monthly report of issued principal amount.</p>
        </div>
        <a class="btn btn-light btn-sm" href="charts.php">Print all</a>
      </div>

      <div class="chart-bars" aria-label="Principal issued chart">
        <div class="chart-column bar-42"><span></span><small>Jan</small></div>
        <div class="chart-column bar-58"><span></span><small>Feb</small></div>
        <div class="chart-column bar-51"><span></span><small>Mar</small></div>
        <div class="chart-column bar-72"><span></span><small>Apr</small></div>
        <div class="chart-column bar-66"><span></span><small>May</small></div>
        <div class="chart-column bar-83"><span></span><small>Jun</small></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-activity" aria-hidden="true"></i>
            <span>Team Activity</span>
          </h2>
          <p class="text-muted mb-0">Recent operational updates.</p>
        </div>
      </div>

      <div class="activity-list">
        <div class="activity-item">
          <span class="activity-dot bg-primary"></span>
          <div>
            <p class="mb-1 fw-semibold">New campaign launched</p>
            <p class="text-muted small mb-0">Marketing team published the May offer.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-success"></span>
          <div>
            <p class="mb-1 fw-semibold">Payment batch cleared</p>
            <p class="text-muted small mb-0">246 invoices were processed successfully.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-warning"></span>
          <div>
            <p class="mb-1 fw-semibold">Support queue rising</p>
            <p class="text-muted small mb-0">Average first response time is 18 minutes.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
            <span>Interest earned</span>
          </h2>
          <p class="text-muted mb-0">Monthly report of total interest earned.</p>
        </div>
        <a class="btn btn-light btn-sm" href="charts.php">Print all</a>
      </div>

      <div class="chart-bars" aria-label="Interest earned chart">
        <div class="chart-column bar-42"><span></span><small>Jan</small></div>
        <div class="chart-column bar-58"><span></span><small>Feb</small></div>
        <div class="chart-column bar-51"><span></span><small>Mar</small></div>
        <div class="chart-column bar-72"><span></span><small>Apr</small></div>
        <div class="chart-column bar-66"><span></span><small>May</small></div>
        <div class="chart-column bar-83"><span></span><small>Jun</small></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-pie-chart" aria-hidden="true"></i>
            <span>Performance</span>
          </h2>
          <p class="text-muted mb-0">Collected loans vs defaulted.</p>
        </div>
      </div>

      <div class="donut-chart mx-auto"><span>88%</span></div>

      <div class="legend-list mt-2">
        <div><span class="legend-dot bg-warning"></span>Defaulted Loans<strong>32%</strong></div>
        <div><span class="legend-dot bg-success"></span>Collected loans<strong>68%</strong></div>
      </div>
    </div>
  </div>

</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';    // ← change to '/partials/main.php' if it lives there