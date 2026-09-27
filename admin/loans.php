<?php
$pageTitle = 'Loans';     // capital C, just cosmetic
$activeNav = 'loans';
ob_start();
?>


          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Overview</p>
                <h1 class="h3 mb-1">Loans</h1>
                <p class="text-muted mb-0">Monitor performance, loans, users, and support from one clean workspace.</p>
              </div>
            </div>
            <div class="heading-actions"><button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export</button><button class="btn btn-primary btn-sm" type="button"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report</button></div>
          </div>

          <section class="row g-3 mt-1" aria-label="Dashboard metrics">

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top">
                  <span class="metric-label">Total Principal</span>
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
                  <span class="metric-label">Interest</span>
                  <span class="metric-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value">Ksh 1,284</div>
                <div class="metric-meta">
                  <span class="text-success">+8.2%</span>
                  <span>new interest</span>
                </div>
              </article>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top">
                  <span class="metric-label">Total Due Amount</span>
                  <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value">Ksh 8,742</div>
                <div class="metric-meta">
                  <span class="text-success">+5.1%</span>
                  <span>amount increase</span>
                </div>
              </article>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top">
                  <span class="metric-label">Total Loans</span>
                  <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value">36</div>
                <div class="metric-meta">
                  <span class="text-danger">3 urgent</span>
                  <span>need review</span>
                </div>
              </article>
            </div>
          </section>

          

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Recent Loans</span></h2>
                <p class="text-muted mb-0">Latest account activity across the workspace.</p>
              </div>
               <div class="heading-actions">
                <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export</button>
               <a class="btn btn-primary btn-sm" href="loan-registration.html"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Loan</a>
              </div>
             
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Loan Id</th>
                        <th scope="col">Client</th>
                        <th scope="col">Date Issued</th>
                        <th scope="col">Principal Amount</th>
                        <th scope="col">Due date</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th></tr></thead>
                <tbody>

                   <tr>                    
                        <td>#1001</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-danger">Defaulted</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="loan-view.html">View</a></td>
                  </tr>

                  <tr>                    
                        <td>#1002</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-success">Active</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="user-details.html">View</a></td>
                  </tr>

                  <tr>                    
                        <td>#1002</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-primary">Active</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="user-details.html">View</a></td>
                  </tr>

                  <tr>                    
                        <td>#1002</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-secondary">Active</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="user-details.html">View</a></td>
                  </tr>

                  <tr>                    
                        <td>#1002</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-warning">Active</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="user-details.html">View</a></td>
                  </tr>

                  <tr>                    
                        <td>#1002</td>
                        <td>
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">                        
                            <div>
                            <p class="fw-semibold mb-0">Rafi Khan</p>
                            <p class="text-muted small mb-0">rafi@example.com</p>
                            </div>
                        </div>
                        </td>
                        <td>Feb 03, 2026</td>
                        <td>Ksh 10000</td>
                        <td>Feb 03, 2026</td>
                        <td><span class="badge text-bg-success">Active</span></td>
                        
                        <td class="text-end"><a class="btn btn-light btn-sm" href="user-details.html">View</a></td>
                  </tr>


                  

                </tbody>
              </table>
            </div>
          </section>
      

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';   // ✅ slash added